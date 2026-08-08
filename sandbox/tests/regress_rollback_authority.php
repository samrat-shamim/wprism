<?php
declare(strict_types=1);

// DUO-3293: offline certification for the external rollback authority.
// Exercises signed exact-match requests, generation/claim fencing, tamper
// detection, and retry after every receipt/event/target publication boundary.

require dirname(__DIR__, 2) . '/recovery/rollback-control.php';

use Duo\Recovery\RollbackControl;

$runtime = dirname(__DIR__, 2) . '/recovery/rollback-control.php';
$tmp = sys_get_temp_dir() . '/duo-rollback-regress-' . bin2hex(random_bytes(8));
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
    $path = tempnam(sys_get_temp_dir(), 'duo-rollback-request-');
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

/** Run a request in a process stopped at one deterministic crash hook. */
function crash_request(string $runtime, string $root, array $request, string $hook): int {
    $path = tempnam(sys_get_temp_dir(), 'duo-rollback-crash-');
    if ($path === false) {
        fail_test('could not allocate crash request');
    }
    file_put_contents($path, RollbackControl::canonical($request) . "\n");
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $runtime, 'request', '--root=' . $root, '--request=' . $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['DUO_ROLLBACK_CRASH_AT' => $hook]
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
    append_event($root, $r2, $secret, 'verifying_prior', 'state_transition', 'advance', 1, 'worker-c', 1, '2026-01-01T00:01:03Z');
    append_event($root, $r2, $secret, 'rolled_back', 'state_transition', 'advance', 1, 'worker-c', 1, '2026-01-01T00:01:04Z');
    ok_test(RollbackControl::status($root)['state'] === 'rolled_back', 'rollback path reaches the only other terminal green state');

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
