<?php
declare(strict_types=1);

// issue #3293: offline certification for the external rollback authority.
// Exercises signed exact-match requests, generation/claim fencing, tamper
// detection, and retry after every receipt/event/target publication boundary.

require dirname(__DIR__, 4) . '/recovery/rollback-control.php';
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php';
require dirname(__DIR__, 4) . '/cli/src/Transport/SshTransport.php';
require dirname(__DIR__, 4) . '/cli/src/Transport/LocalTransport.php';
require dirname(__DIR__, 4) . '/cli/src/Recovery/RollbackAuthority.php';
require dirname(__DIR__, 4) . '/cli/src/Recovery/VerifiedRollbackProfile.php';

use WPrism\Canon;
use WPrism\Orchestrator\LocalTransport;
use WPrism\Orchestrator\RollbackAuthority;
use WPrism\Orchestrator\SshTransport;
use WPrism\Orchestrator\VerifiedRollbackProfile;
use WPrism\Recovery\RollbackControl;

$runtime = dirname(__DIR__, 4) . '/recovery/rollback-control.php';
$tmp = sys_get_temp_dir() . '/wprism-rollback-regress-' . bin2hex(random_bytes(8));
$keyId = 'offline-key-1';
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);

function fail_test(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function ok_test(bool $condition, string $message): void {
    if (!$condition) {
        fail_test($message);
    }
    echo "ok: $message\n";
}

function refuses(callable $operation, string $message): void {
    try {
        $operation();
    } catch (Throwable $e) {
        echo "ok: $message\n";
        return;
    }
    fail_test($message);
}

function remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

function copy_tree(string $from, string $to): void {
    if (is_file($from)) {
        if (!copy($from, $to)) {
            fail_test("could not copy $from");
        }
        return;
    }
    if (!mkdir($to, 0700, true) && !is_dir($to)) {
        fail_test("could not create $to");
    }
    foreach (scandir($from) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            copy_tree($from . '/' . $name, $to . '/' . $name);
        }
    }
}

/** @return array<string,mixed> */
function receipt(array $status, int $generation, string $receiptId, string $created): array {
    $hash = static fn(string $value): string => hash('sha256', $value);
    return [
        'adapter_versions_sha256' => $hash('adapters'),
        'artifact_hash' => $hash('artifact-' . $generation),
        'checkpoint_sha256' => $hash('checkpoint-' . $generation),
        'claim_ttl_seconds' => 30,
        'code_release_metadata_sha256' => $hash('code-release-' . $generation),
        'created_at' => $created,
        'encryption_key_id' => 'age-key-2026',
        'exclusion_token_sha256' => $hash('exclusion-' . $generation),
        'format' => RollbackControl::RECEIPT_FORMAT,
        'generation' => $generation,
        'ledger_session_sha256' => $hash('ledger-' . $generation),
        'lifecycle_receipts_sha256' => $hash('lifecycle-' . $generation),
        'owner' => 'controller:offline',
        'prior_code_descriptor_sha256' => $hash('code-' . $generation),
        'prior_verifier_inputs_sha256' => $hash('verifiers-' . $generation),
        'receipt_id' => $receiptId,
        'resources_inventory_sha256' => $hash('resources-' . $generation),
        'retention_until' => '2027-01-01T00:00:00Z',
        'runtime_fingerprints_sha256' => $hash('runtime-' . $generation),
        'signing_key_id' => 'offline-key-1',
        'target_id' => (string) $status['target_id'],
        'uploads_inventory_sha256' => $hash('uploads-' . $generation),
    ];
}

/** @return array<string,mixed> */
function event(
    array $receipt,
    int $sequence,
    string $previous,
    string $state,
    string $operationStatus,
    string $operationId,
    int $attempt,
    string $claimant,
    int $epoch,
    string $timestamp,
    ?string $input = null,
    ?string $result = null
): array {
    $seconds = strtotime($timestamp);
    if ($seconds === false) {
        fail_test('bad test timestamp');
    }
    return [
        'artifact_hash' => $receipt['artifact_hash'],
        'attempt' => $attempt,
        'claim_epoch' => $epoch,
        'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $seconds + (int) $receipt['claim_ttl_seconds']),
        'claimant' => $claimant,
        'format' => RollbackControl::EVENT_FORMAT,
        'generation' => $receipt['generation'],
        'input_sha256' => $input ?? hash('sha256', 'input-' . $operationId),
        'operation_id' => $operationId,
        'operation_status' => $operationStatus,
        'owner' => $receipt['owner'],
        'previous_event_sha256' => $previous,
        'receipt_id' => $receipt['receipt_id'],
        'result_sha256' => $result ?? hash('sha256', 'result-' . $operationId),
        'sequence' => $sequence,
        'signing_key_id' => $receipt['signing_key_id'],
        'state' => $state,
        'target_id' => $receipt['target_id'],
        'timestamp' => $timestamp,
    ];
}

/** @return array<string,mixed> */
function signed_request(string $action, array $event, ?array $receipt, string $secret): array {
    return [
        'action' => $action,
        'event' => RollbackControl::sign($event, 'offline-key-1', $secret),
        'receipt' => $receipt === null ? null : RollbackControl::sign($receipt, 'offline-key-1', $secret),
    ];
}

/** @return array<string,mixed> */
function submit(string $root, array $request): array {
    $path = tempnam(sys_get_temp_dir(), 'wprism-rollback-request-');
    if ($path === false) {
        fail_test('could not allocate request');
    }
    file_put_contents($path, RollbackControl::canonical($request) . "\n");
    try {
        return RollbackControl::handleRequest($root, $path);
    } finally {
        @unlink($path);
    }
}

/** @return array<string,mixed> */
function append_event(
    string $root,
    array $receipt,
    string $secret,
    string $state,
    string $operationStatus,
    string $operationId,
    int $attempt,
    string $claimant,
    int $epoch,
    string $timestamp,
    ?string $input = null,
    ?string $result = null
): array {
    $status = RollbackControl::status($root);
    $payload = event(
        $receipt,
        (int) $status['sequence'] + 1,
        (string) $status['head_event_sha256'],
        $state,
        $operationStatus,
        $operationId,
        $attempt,
        $claimant,
        $epoch,
        $timestamp,
        $input,
        $result
    );
    return submit($root, signed_request('append', $payload, null, $secret));
}

/** Run one request with ambient fault injection and optional root authority. */
function crash_request(
    string $runtime,
    string $root,
    array $request,
    string $hook,
    bool $certificationMode = true
): int {
    $path = tempnam(sys_get_temp_dir(), 'wprism-rollback-crash-');
    if ($path === false) {
        fail_test('could not allocate crash request');
    }
    $marker = $root . '/.certification-crash-mode';
    if ($certificationMode) {
        file_put_contents($marker, "wprism-rollback-certification-crash-mode/v1\n");
        chmod($marker, 0600);
    }
    file_put_contents($path, RollbackControl::canonical($request) . "\n");
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $runtime, 'request', '--root=' . $root, '--request=' . $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['WPRISM_ROLLBACK_CRASH_AT' => $hook]
    );
    if (!is_resource($process)) {
        fail_test('could not start crash process');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    @unlink($path);
    @unlink($marker);
    return $code;
}

try {
    $root = $tmp . '/main/control';
    $initial = RollbackControl::initialize($root);
    RollbackControl::installPublicKey($root, $keyId, base64_encode($public));
    ok_test(($initial['generation'] ?? -1) === 0 && strlen((string) $initial['target_id']) === 32, 'initialization creates one stable target identity');
    ok_test(RollbackControl::initialize($root)['target_id'] === $initial['target_id'], 're-initialization preserves target identity');

    $r1 = receipt($initial, 1, str_repeat('a', 48), '2026-01-01T00:00:00Z');
    $e1 = event($r1, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'worker-a', 1, '2026-01-01T00:00:00Z');
    $claim1 = signed_request('claim', $e1, $r1, $secret);
    $status = submit($root, $claim1);
    ok_test($status['state'] === 'prepared' && $status['generation'] === 1, 'signed receipt atomically claims the next generation');
    ok_test(submit($root, $claim1)['head_event_sha256'] === $status['head_event_sha256'], 'exact claim retry is idempotent after target publication');
    $otherReceipt = receipt($initial, 1, str_repeat('d', 48), '2026-01-01T00:00:00Z');
    $otherEvent = event($otherReceipt, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'worker-a', 1, '2026-01-01T00:00:00Z');
    refuses(fn() => submit($root, signed_request('claim', $otherEvent, $otherReceipt, $secret)), 'nonterminal generation refuses another claim');

    $input = hash('sha256', 'database-input');
    append_event($root, $r1, $secret, 'prepared', 'prepared', 'database', 1, 'worker-a', 1, '2026-01-01T00:00:01Z', $input);
    refuses(
        fn() => append_event($root, $r1, $secret, 'promoting', 'state_transition', 'advance', 1, 'worker-a', 1, '2026-01-01T00:00:02Z'),
        'state transition refuses an incomplete resource operation'
    );
    append_event($root, $r1, $secret, 'prepared', 'completed', 'database', 1, 'worker-a', 1, '2026-01-01T00:00:03Z', $input);
    append_event($root, $r1, $secret, 'promoting', 'state_transition', 'advance', 1, 'worker-a', 1, '2026-01-01T00:00:04Z');

    $beforeForeign = RollbackControl::status($root);
    $foreign = event($r1, (int) $beforeForeign['sequence'] + 1, (string) $beforeForeign['head_event_sha256'], 'verifying_new', 'state_transition', 'advance', 1, 'worker-b', 1, '2026-01-01T00:00:05Z');
    refuses(fn() => submit($root, signed_request('append', $foreign, null, $secret)), 'foreign claimant is fenced before expiry');

    $beforeTakeover = RollbackControl::status($root);
    $takeover = event($r1, (int) $beforeTakeover['sequence'] + 1, (string) $beforeTakeover['head_event_sha256'], 'promoting', 'takeover', 'operator-takeover', 1, 'worker-b', 2, '2026-01-01T00:00:35Z');
    submit($root, signed_request('append', $takeover, null, $secret));
    refuses(
        fn() => append_event($root, $r1, $secret, 'verifying_new', 'state_transition', 'advance', 1, 'worker-a', 1, '2026-01-01T00:00:36Z'),
        'expired takeover advances claim epoch and fences former claimant'
    );
    append_event($root, $r1, $secret, 'verifying_new', 'state_transition', 'advance', 1, 'worker-b', 2, '2026-01-01T00:00:36Z');
    append_event($root, $r1, $secret, 'committed', 'state_transition', 'advance', 1, 'worker-b', 2, '2026-01-01T00:00:37Z');
    ok_test(RollbackControl::status($root)['terminal'] === true, 'committed is terminal and green');

    $r2 = receipt(RollbackControl::status($root), 2, str_repeat('b', 48), '2026-01-01T00:01:00Z');
    $e2 = event($r2, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'worker-c', 1, '2026-01-01T00:01:00Z');
    submit($root, signed_request('claim', $e2, $r2, $secret));
    append_event($root, $r2, $secret, 'rollback_pending', 'state_transition', 'advance', 1, 'worker-c', 1, '2026-01-01T00:01:01Z');
    append_event($root, $r2, $secret, 'rolling_back', 'state_transition', 'advance', 1, 'worker-c', 1, '2026-01-01T00:01:02Z');
    $databaseInput = hash('sha256', 'database-restore-input');
    append_event($root, $r2, $secret, 'rolling_back', 'prepared', 'database_restore', 1, 'worker-c', 1, '2026-01-01T00:01:03Z', $databaseInput);
    append_event($root, $r2, $secret, 'rolling_back', 'completed', 'database_restore', 1, 'worker-c', 1, '2026-01-01T00:01:04Z', $databaseInput);
    append_event($root, $r2, $secret, 'verifying_prior', 'state_transition', 'advance', 1, 'worker-c', 1, '2026-01-01T00:01:05Z');

    $beforeMissingVerify = RollbackControl::status($root);
    refuses(
        fn() => append_event($root, $r2, $secret, 'rolled_back', 'state_transition', 'advance', 1, 'worker-c', 1, '2026-01-01T00:01:06Z'),
        'terminal rollback refuses zero completed prior verification operations'
    );
    ok_test(
        RollbackControl::canonical(RollbackControl::status($root)) === RollbackControl::canonical($beforeMissingVerify),
        'missing prior verification refusal is mutation-free and preserves the exact authority head'
    );

    $priorInput = hash('sha256', 'prior-verify-input');
    append_event($root, $r2, $secret, 'verifying_prior', 'prepared', 'prior_verify', 1, 'worker-c', 1, '2026-01-01T00:01:07Z', $priorInput);
    append_event($root, $r2, $secret, 'verifying_prior', 'completed', 'prior_verify', 1, 'worker-c', 1, '2026-01-01T00:01:08Z', $priorInput);

    $beforeTakeoverVerify = RollbackControl::status($root);
    $verifyTakeover = event($r2, (int) $beforeTakeoverVerify['sequence'] + 1, (string) $beforeTakeoverVerify['head_event_sha256'], 'verifying_prior', 'takeover', 'operator-takeover', 1, 'worker-e', 2, '2026-01-01T00:01:39Z');
    submit($root, signed_request('append', $verifyTakeover, null, $secret));
    $beforeStaleVerify = RollbackControl::status($root);
    refuses(
        fn() => append_event($root, $r2, $secret, 'rolled_back', 'state_transition', 'advance', 1, 'worker-e', 2, '2026-01-01T00:01:40Z'),
        'terminal rollback refuses prior verification completed only by an earlier claim epoch'
    );
    ok_test(
        RollbackControl::canonical(RollbackControl::status($root)) === RollbackControl::canonical($beforeStaleVerify),
        'stale-epoch prior verification refusal is mutation-free and preserves the exact authority head'
    );
    append_event($root, $r2, $secret, 'verifying_prior', 'prepared', 'prior_verify', 2, 'worker-e', 2, '2026-01-01T00:01:41Z', $priorInput);
    append_event($root, $r2, $secret, 'verifying_prior', 'completed', 'prior_verify', 2, 'worker-e', 2, '2026-01-01T00:01:42Z', $priorInput);
    append_event($root, $r2, $secret, 'rolled_back', 'state_transition', 'advance', 1, 'worker-e', 2, '2026-01-01T00:01:43Z');
    ok_test(RollbackControl::status($root)['state'] === 'rolled_back', 'completed current-epoch verification and declared restore proof admit rolled_back');
    $audit = RollbackControl::auditEvidence($root);
    ok_test(($audit['format'] ?? '') === 'wprism-rollback-audit/v1'
        && ($audit['state'] ?? '') === 'rolled_back'
        && count($audit['events'] ?? []) === (int) RollbackControl::status($root)['sequence']
        && preg_match('/^[a-f0-9]{64}$/', (string) ($audit['receipt_sha256'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/', (string) ($audit['event_chain_sha256'] ?? '')) === 1,
        'hash-only audit export binds the verified signed receipt and complete event chain');

    $current = RollbackControl::status($root);
    $r3 = receipt($current, 3, str_repeat('c', 48), '2026-01-01T00:02:00Z');
    $e3 = event($r3, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'worker-d', 1, '2026-01-01T00:02:00Z');
    $wrongTarget = $r3;
    $wrongTarget['target_id'] = str_repeat('f', 32);
    $wrongTargetEvent = $e3;
    $wrongTargetEvent['target_id'] = str_repeat('f', 32);
    refuses(fn() => submit($root, signed_request('claim', $wrongTargetEvent, $wrongTarget, $secret)), 'wrong target receipt is refused');
    $stale = $r3;
    $stale['generation'] = 2;
    $staleEvent = $e3;
    $staleEvent['generation'] = 2;
    refuses(fn() => submit($root, signed_request('claim', $staleEvent, $stale, $secret)), 'stale generation is refused and never reused');
    submit($root, signed_request('claim', $e3, $r3, $secret));

    $base = RollbackControl::status($root);
    foreach (['owner', 'artifact_hash', 'generation', 'target_id'] as $field) {
        $bad = event($r3, (int) $base['sequence'] + 1, (string) $base['head_event_sha256'], 'promoting', 'state_transition', 'advance', 1, 'worker-d', 1, '2026-01-01T00:02:01Z');
        $bad[$field] = $field === 'generation' ? 99 : ($field === 'target_id' ? str_repeat('e', 32) : ($field === 'artifact_hash' ? str_repeat('d', 64) : 'controller:other'));
        refuses(fn() => submit($root, signed_request('append', $bad, null, $secret)), "event with substituted $field is refused");
    }

    $winner = event($r3, (int) $base['sequence'] + 1, (string) $base['head_event_sha256'], 'promoting', 'state_transition', 'advance-a', 1, 'worker-d', 1, '2026-01-01T00:02:01Z');
    $loser = event($r3, (int) $base['sequence'] + 1, (string) $base['head_event_sha256'], 'rollback_pending', 'state_transition', 'advance-b', 1, 'worker-d', 1, '2026-01-01T00:02:01Z');
    submit($root, signed_request('append', $winner, null, $secret));
    refuses(fn() => submit($root, signed_request('append', $loser, null, $secret)), 'concurrent stale sequence loses compare-and-swap');

    $replacementPair = sodium_crypto_sign_keypair();
    refuses(
        fn() => RollbackControl::installPublicKey($root, $keyId, base64_encode(sodium_crypto_sign_publickey($replacementPair))),
        'public key ids are immutable and cannot invalidate old receipts'
    );

    foreach (['target', 'receipt', 'event'] as $kind) {
        $copy = $tmp . '/tamper-' . $kind . '/control';
        copy_tree(dirname($root), dirname($copy));
        if ($kind === 'target') {
            $path = $copy . '/target.json';
        } elseif ($kind === 'receipt') {
            $path = glob(dirname($copy) . '/rollback/' . str_repeat('c', 48) . '/receipt.json')[0] ?? '';
        } else {
            $paths = glob(dirname($copy) . '/rollback/' . str_repeat('c', 48) . '/events/*.json') ?: [];
            $path = end($paths) ?: '';
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            fail_test("could not prepare $kind tamper");
        }
        if ($kind === 'target') {
            $decoded['claimant'] = 'worker-x';
        } elseif ($kind === 'receipt') {
            $decoded['payload']['owner'] = 'controller:other';
        } else {
            $decoded['payload']['claimant'] = 'worker-x';
        }
        file_put_contents($path, RollbackControl::canonical($decoded) . "\n");
        refuses(fn() => RollbackControl::status($copy), "$kind tampering makes status non-green");
    }

    $legacyRoot = $tmp . '/legacy-v1/control';
    $legacyStatus = RollbackControl::initialize($legacyRoot);
    RollbackControl::installPublicKey($legacyRoot, $keyId, base64_encode($public));
    $legacyReceipt = receipt($legacyStatus, 1, str_repeat('b', 48), '2026-01-02T00:00:00Z');
    $legacyReceipt['format'] = 'wprism-rollback-receipt/v1';
    unset($legacyReceipt['code_release_metadata_sha256']);
    $legacyEvent = event($legacyReceipt, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'legacy-worker', 1, '2026-01-02T00:00:00Z');
    refuses(fn() => submit($legacyRoot, signed_request('claim', $legacyEvent, $legacyReceipt, $secret)), 'rollback receipt v1 is refused after the greenfield format cutover');

    $compiledUploads = [['attachment_uuid' => 'plan-upload-marker']];
    $compiledEffects = [['effect' => ['id' => 'plan-effect-marker']]];
    $compiledLifecycleEffects = [['effect' => ['id' => 'plan-lifecycle-effect-marker']]];
    $compiledActions = [[
        'declaration_hash' => hash('sha256', 'plan-action-marker'),
        'index' => 4,
        'manifest' => 'plan-adapter',
    ]];
    $compiledCode = [
        'files' => [['path' => 'plugins/acme/acme.php', 'sha256' => hash('sha256', 'plan-code')]],
        'format' => 'wprism-code/v1',
        'layout' => 'wp-content',
        'owned_roots' => ['plugins/acme'],
        'plugin_main_files' => [['basename' => 'acme/acme.php', 'path' => 'plugins/acme/acme.php', 'sha256' => hash('sha256', 'plan-code')]],
        'source' => 'code/wp-content',
        'theme_slugs' => [],
        'theme_templates' => [],
    ];
    $compiledCode['code_revision'] = hash('sha256', Canon::encode($compiledCode));
    $compiledPlan = [
        'artifact_hash' => hash('sha256', 'plan-artifact'),
        'code' => $compiledCode,
        'effects_inventory' => $compiledEffects,
        'lifecycle_effects_inventory' => $compiledLifecycleEffects,
        'resolved_adapters' => [['name' => 'plan-adapter', 'version' => '1.0.0']],
        'selected_actions' => $compiledActions,
        'uploads_inventory' => $compiledUploads,
    ];
    $claimFields = VerifiedRollbackProfile::claimFields(
        $compiledPlan,
        ['claim_ttl_seconds' => 90, 'encryption_key_id' => 'kms-plan', 'retention_seconds' => 3600],
        'controller:plan-test',
        '2026-08-08T00:00:00Z'
    );
    ok_test(
        $claimFields['upload_inventory'] === $compiledUploads
            && $claimFields['effect_inventory'] === $compiledEffects,
        'automatic profile carries only the exact plan-selected execution inventories into the receipt claim'
    );
    ok_test(
        $claimFields['resources_inventory_sha256'] === hash('sha256', RollbackControl::canonical([
            'code' => $compiledCode,
            'effects_inventory' => $compiledEffects,
            'selected_actions' => $compiledActions,
            'uploads_inventory' => $compiledUploads,
        ])),
        'automatic profile binds the selected action identities into the signed resources address'
    );
    ok_test(
        ($claimFields['desired_code_inventory'] ?? null) === $compiledCode
            && !array_key_exists('desired_descriptor_sha256', $claimFields)
            && !array_key_exists('allow_deletes', $claimFields)
            && $claimFields['retention_until'] === '2026-08-08T01:00:00Z',
        'ordinary automatic claim binds code and retention while preserving the v2 rolling-upgrade wire contract'
    );
    $deleteClaimFields = VerifiedRollbackProfile::claimFields(
        $compiledPlan,
        ['claim_ttl_seconds' => 90, 'encryption_key_id' => 'kms-plan', 'retention_seconds' => 3600],
        'controller:plan-delete-test',
        '2026-08-08T00:00:00Z',
        null,
        true
    );
    ok_test(
        ($deleteClaimFields['allow_deletes'] ?? null) === true,
        'only explicit deletion intent moves the automatic claim to its v3 wire contract'
    );
    $codeChangeClaimFields = VerifiedRollbackProfile::claimFields(
        $compiledPlan,
        ['claim_ttl_seconds' => 90, 'encryption_key_id' => 'kms-plan', 'retention_seconds' => 3600],
        'controller:plan-code-change-test',
        '2026-08-08T00:00:00Z',
        null,
        false,
        true
    );
    ok_test(
        $codeChangeClaimFields['effect_inventory'] === array_merge(
            $compiledEffects,
            $compiledLifecycleEffects
        )
            && !hash_equals(
                (string) $claimFields['resources_inventory_sha256'],
                (string) $codeChangeClaimFields['resources_inventory_sha256']
            ),
        'lifecycle recovery authority is signed only when the code preflight selected a code transition'
    );
    $providerCommand = ['/bin/true'];
    $automaticTransport = new SshTransport('automatic-test', [
        'host' => 'fixture-host',
        'repo_path' => '/srv/site',
        'rollback_key_id' => 'offline-key',
        'rollback_recovery' => [
            'adapters' => [
                'code_restore' => $providerCommand,
                'database_restore' => $providerCommand,
                'prior_verify' => $providerCommand,
                'storage_restore' => $providerCommand,
            ],
            'checkpoint_provider' => $providerCommand,
            'code_release_provider' => $providerCommand,
            'effect_provider' => $providerCommand,
            'exclusion_provider' => $providerCommand,
            'timeout_seconds' => 5,
            'upload_provider' => $providerCommand,
        ],
        'rollback_signing_key' => '/tmp/offline-signing-key',
        'transport' => 'ssh',
        'verified_rollback' => [
            'claim_ttl_seconds' => 90,
            'encryption_key_id' => 'kms-plan',
            'retention_seconds' => 3600,
        ],
        'wp_path' => '/srv/wordpress',
    ]);
    $selection = VerifiedRollbackProfile::select(
        $automaticTransport,
        $compiledPlan,
        ['available' => true, 'ok' => true, 'recovery_ready' => true]
    );
    ok_test(
        $selection['automatic'] === true,
        'automatic profile selection is capability-based when every provider and policy is ready'
    );
    // WPRISM: the same seven-predicate selection off SSH. The profile is chosen
    // from declared capability, so a local environment carrying the identical
    // provider set and policy must reach the identical answer — that is the
    // whole claim of the RecoveryTransport seam.
    $localAutomaticConfig = [
        '_machine_local' => true,
        'repo_path' => $tmp . '/local-site',
        'rollback_key_id' => 'offline-key',
        'rollback_recovery' => [
            'adapters' => [
                'code_restore' => $providerCommand,
                'database_restore' => $providerCommand,
                'prior_verify' => $providerCommand,
                'storage_restore' => $providerCommand,
            ],
            'checkpoint_provider' => $providerCommand,
            'code_release_provider' => $providerCommand,
            'effect_provider' => $providerCommand,
            'exclusion_provider' => $providerCommand,
            'timeout_seconds' => 5,
            'upload_provider' => $providerCommand,
        ],
        'rollback_signing_key' => '/tmp/offline-signing-key',
        'transport' => 'local',
        'verified_rollback' => [
            'claim_ttl_seconds' => 90,
            'encryption_key_id' => 'kms-plan',
            'retention_seconds' => 3600,
        ],
        'wp_path' => $tmp . '/local-wordpress',
    ];
    $localAutomatic = new LocalTransport('automatic-local-test', $localAutomaticConfig);
    $localSelection = VerifiedRollbackProfile::select(
        $localAutomatic,
        $compiledPlan,
        ['available' => true, 'ok' => true, 'recovery_ready' => true]
    );
    ok_test(
        $localSelection === $selection,
        'a machine-local environment with the same provider set reaches the byte-identical automatic selection'
    );
    // The privilege gate, not just the capability: the same configuration with
    // no machine-local provenance is a loud refusal at construction, because on
    // a local target the Ed25519 secret and the control root share a machine.
    $localUnauthorizedConfig = $localAutomaticConfig;
    unset($localUnauthorizedConfig['_machine_local']);
    refuses(
        fn() => new LocalTransport('unauthorized-local-test', $localUnauthorizedConfig),
        'a local rollback authority without machine-local authorization refuses to arm its keys'
    );
    ok_test(
        (new LocalTransport('bare-local-test', [
            'repo_path' => $tmp . '/local-site',
            'transport' => 'local',
            'wp_path' => $tmp . '/local-wordpress',
        ]))->carriesRollbackAuthority() === false,
        'a local environment that never opted in carries no rollback authority to read or fence on'
    );

    $manualTransport = new SshTransport('manual-test', [
        'host' => 'fixture-host',
        'repo_path' => '/srv/site',
        'transport' => 'ssh',
        'wp_path' => '/srv/wordpress',
    ]);
    $manualSelection = VerifiedRollbackProfile::select($manualTransport, $compiledPlan, []);
    ok_test(
        $manualSelection['automatic'] === false
            && str_contains($manualSelection['reason'], 'missing'),
        'missing declared capabilities select the loud operator-directed fallback without filesystem inference'
    );
    $fakeBin = $tmp . '/fake-bin';
    mkdir($fakeBin, 0700, true);
    file_put_contents($fakeBin . '/ssh', "#!/bin/sh\nexit 44\n");
    chmod($fakeBin . '/ssh', 0700);
    $oldPath = (string) getenv('PATH');
    putenv('PATH=' . $fakeBin . ':' . $oldPath);
    try {
        $missingRuntime = RollbackAuthority::status($manualTransport);
    } finally {
        putenv('PATH=' . $oldPath);
    }
    ok_test(
        $missingRuntime['available'] === false && $missingRuntime['ok'] === false,
        'missing authority runtime is unavailable and never reports ok=true'
    );
    refuses(
        fn() => VerifiedRollbackProfile::claimFields(
            array_replace($compiledPlan, ['effects_inventory' => null]),
            ['claim_ttl_seconds' => 90, 'encryption_key_id' => 'kms-plan', 'retention_seconds' => 3600],
            'controller:plan-test',
            '2026-08-08T00:00:00Z'
        ),
        'automatic claim refuses a missing compiled plan inventory'
    );

    $productionRoot = $tmp . '/production-env/control';
    $productionStatus = RollbackControl::initialize($productionRoot);
    RollbackControl::installPublicKey($productionRoot, $keyId, base64_encode($public));
    $productionReceipt = receipt($productionStatus, 1, str_repeat('9', 48), '2026-01-31T00:00:00Z');
    $productionEvent = event($productionReceipt, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'production-worker', 1, '2026-01-31T00:00:00Z');
    $productionRequest = signed_request('claim', $productionEvent, $productionReceipt, $secret);
    ok_test(
        crash_request($runtime, $productionRoot, $productionRequest, 'claim:after-receipt', false) === 0,
        'ambient crash environment cannot terminate an unmarked production authority root'
    );
    ok_test(
        RollbackControl::status($productionRoot)['state'] === 'prepared',
        'unmarked production authority completes the exact request despite ambient crash input'
    );

    $claimHooks = [
        'receipt:before-write', 'receipt:after-file-fsync', 'receipt:after-rename', 'receipt:after-dir-fsync',
        'claim:after-receipt',
        'event:before-write', 'event:after-file-fsync', 'event:after-rename', 'event:after-dir-fsync',
        'claim:after-event',
        'target:before-write', 'target:after-file-fsync', 'target:after-rename', 'target:after-dir-fsync',
        'claim:after-target',
    ];
    foreach ($claimHooks as $index => $hook) {
        $crashRoot = $tmp . '/claim-crash-' . $index . '/control';
        $crashStatus = RollbackControl::initialize($crashRoot);
        RollbackControl::installPublicKey($crashRoot, $keyId, base64_encode($public));
        $crashReceipt = receipt($crashStatus, 1, str_pad(dechex($index + 1), 48, 'd'), '2026-02-01T00:00:00Z');
        $crashEvent = event($crashReceipt, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'crash-worker', 1, '2026-02-01T00:00:00Z');
        $request = signed_request('claim', $crashEvent, $crashReceipt, $secret);
        ok_test(crash_request($runtime, $crashRoot, $request, $hook) === 97, "claim crash hook $hook reached");
        $recovered = submit($crashRoot, $request);
        ok_test($recovered['state'] === 'prepared' && $recovered['sequence'] === 1, "claim retry recovers $hook");
    }

    $appendHooks = [
        'event:before-write', 'event:after-file-fsync', 'event:after-rename', 'event:after-dir-fsync',
        'append:after-event',
        'target:before-write', 'target:after-file-fsync', 'target:after-rename', 'target:after-dir-fsync',
        'append:after-target',
    ];
    foreach ($appendHooks as $index => $hook) {
        $crashRoot = $tmp . '/append-crash-' . $index . '/control';
        $crashStatus = RollbackControl::initialize($crashRoot);
        RollbackControl::installPublicKey($crashRoot, $keyId, base64_encode($public));
        $crashReceipt = receipt($crashStatus, 1, str_pad(dechex($index + 101), 48, 'e'), '2026-03-01T00:00:00Z');
        $first = event($crashReceipt, 1, str_repeat('0', 64), 'prepared', 'state_transition', 'promotion-claim', 1, 'crash-worker', 1, '2026-03-01T00:00:00Z');
        submit($crashRoot, signed_request('claim', $first, $crashReceipt, $secret));
        $active = RollbackControl::status($crashRoot);
        $next = event($crashReceipt, 2, (string) $active['head_event_sha256'], 'promoting', 'state_transition', 'advance', 1, 'crash-worker', 1, '2026-03-01T00:00:01Z');
        $request = signed_request('append', $next, null, $secret);
        ok_test(crash_request($runtime, $crashRoot, $request, $hook) === 97, "append crash hook $hook reached");
        $recovered = submit($crashRoot, $request);
        ok_test($recovered['state'] === 'promoting' && $recovered['sequence'] === 2, "append retry recovers $hook");
    }

    echo "REGRESS_ROLLBACK_AUTHORITY PASSED\n";
} finally {
    sodium_memzero($secret);
    remove_tree($tmp);
}
