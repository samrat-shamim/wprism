<?php
/**
 * Loopback-only signed control endpoint for the cloud-preview product-path
 * regression. This is a fixture server, not a production HTTP implementation.
 *
 * usage: php cloud-preview-control-endpoint.php <ready> <stop> <log>
 *        <expected.json> <client-public.key> <server-secret.key> <fence-state> <response-mode>
 */
declare(strict_types=1);

if ($argc !== 9) {
    fwrite(STDERR, "usage: cloud-preview-control-endpoint.php <ready> <stop> <log> <expected.json> <client-public.key> <server-secret.key> <fence-state> <response-mode>\n");
    exit(2);
}

[
    $script, $readyPath, $stopPath, $logPath, $expectedPath,
    $clientPublicPath, $serverSecretPath, $fenceStatePath, $responseModePath,
] = $argv;

/** @return mixed */
function cloud_fixture_canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
        $value[$key] = cloud_fixture_canonicalize($child);
    }
    return $value;
}

function cloud_fixture_encode(mixed $value): string {
    return json_encode(
        cloud_fixture_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

/** @param array<string,mixed> $payload */
function cloud_fixture_request_id(array $payload): string {
    return hash(
        'sha256',
        "duo-cloud-preview-command/v1\0" . ($payload['tenant_id'] ?? '')
            . "\0" . ($payload['site_id'] ?? '')
            . "\0" . ($payload['operation_id'] ?? '')
            . "\0" . ($payload['command_phase'] ?? '')
            . "\0" . ($payload['command_index'] ?? '')
    );
}

/** @param array<string,mixed> $payload */
function cloud_fixture_lifecycle_request_id(array $payload): string {
    return hash(
        'sha256',
        "duo-cloud-preview-lifecycle/v1\0" . ($payload['tenant_id'] ?? '')
            . "\0" . ($payload['site_id'] ?? '')
            . "\0" . ($payload['environment'] ?? '')
            . "\0" . ($payload['operation_id'] ?? '')
            . "\0" . ($payload['action'] ?? '')
            . "\0" . hash('sha256', cloud_fixture_encode($payload['input'] ?? null))
    );
}

/** @param array<string,mixed> $payload */
function cloud_fixture_origin_request_id(array $payload, string $keyId): string {
    return hash(
        'sha256',
        "duo-cloud-origin-controller-request/v1\0$keyId\0"
            . ($payload['tenant_id'] ?? '') . "\0" . ($payload['site_id'] ?? '') . "\0"
            . ($payload['operation_id'] ?? '') . "\0" . ($payload['action'] ?? '') . "\0"
            . hash('sha256', cloud_fixture_encode($payload['input'] ?? null))
    );
}

/** @return array<string,mixed> */
function cloud_fixture_read_object(string $path, string $label): array {
    $raw = @file_get_contents($path);
    try {
        $value = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable $error) {
        throw new RuntimeException("$label is malformed JSON", 0, $error);
    }
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException("$label must be an object");
    }
    return $value;
}

function cloud_fixture_key(string $path, int $length, string $label): string {
    $encoded = trim((string) @file_get_contents($path));
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || strlen($decoded) !== $length) {
        throw new RuntimeException("$label is not canonical base64 key material");
    }
    return $decoded;
}

/** @param array<string,mixed> $row */
function cloud_fixture_log(string $path, array $row): void {
    $bytes = cloud_fixture_encode($row) . "\n";
    if (file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('could not append the cloud control fixture log');
    }
}

/** @return array{method:string,path:string,body:string} */
function cloud_fixture_http_request($connection): array {
    $deadline = microtime(true) + 2.0;
    $headers = '';
    while (!str_contains($headers, "\r\n\r\n")) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('HTTP request exceeded the fixture deadline');
        }
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1000000);
        stream_set_timeout($connection, $seconds, $microseconds);
        $chunk = fread($connection, 8192);
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('incomplete HTTP headers');
        }
        $headers .= $chunk;
        if (strlen($headers) > 65536) {
            throw new RuntimeException('HTTP headers exceeded fixture limit');
        }
    }
    [$head, $body] = explode("\r\n\r\n", $headers, 2);
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    if (!is_string($requestLine)
        || preg_match('#^(POST) ([^ ]+) HTTP/1\.[01]$#D', $requestLine, $match) !== 1) {
        throw new RuntimeException('unsupported HTTP request line');
    }
    $length = null;
    foreach ($lines as $line) {
        if (stripos($line, 'Content-Length:') === 0) {
            $rawLength = trim(substr($line, strlen('Content-Length:')));
            if (preg_match('/^(?:0|[1-9][0-9]{0,6})$/D', $rawLength) !== 1) {
                throw new RuntimeException('invalid HTTP content length');
            }
            $length = (int) $rawLength;
        }
    }
    if ($length === null || $length > 1048576) {
        throw new RuntimeException('missing or excessive HTTP content length');
    }
    while (strlen($body) < $length) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('HTTP request exceeded the fixture deadline');
        }
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1000000);
        stream_set_timeout($connection, $seconds, $microseconds);
        $chunk = fread($connection, $length - strlen($body));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('incomplete HTTP request body');
        }
        $body .= $chunk;
    }
    if (strlen($body) !== $length) {
        throw new RuntimeException('HTTP request body length mismatch');
    }
    return ['method' => $match[1], 'path' => $match[2], 'body' => $body];
}

function cloud_fixture_http_response($connection, int $status, string $body): void {
    $reason = $status === 200 ? 'OK' : 'Forbidden';
    $headers = "HTTP/1.1 $status $reason\r\n"
        . "Content-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($connection, $headers . $body);
}

function cloud_fixture_stalled_response($connection): void {
    fwrite(
        $connection,
        "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 64\r\nConnection: close\r\n\r\n"
    );
    fflush($connection);
}

/** @param array<string,mixed> $value @param list<string> $keys */
function cloud_fixture_exact_keys(array $value, array $keys, string $label): void {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    if ($actual !== $keys) {
        throw new RuntimeException("$label has missing or unknown fields");
    }
}

/**
 * @param array<string,mixed> $envelope
 * @param array<string,mixed> $expected
 * @return array<string,mixed>
 */
function cloud_fixture_verify_request(array $envelope, array $expected, string $clientPublic): array {
    cloud_fixture_exact_keys($envelope, ['format', 'key_id', 'payload', 'signature'], 'signed request');
    if (($envelope['format'] ?? null) !== 'duo-cloud-preview-signed-envelope/v1'
        || ($envelope['key_id'] ?? null) !== $expected['request_key_id']
        || !is_array($envelope['payload'] ?? null)
        || array_is_list($envelope['payload'])) {
        throw new RuntimeException('signed request envelope is not bound to the paired site key');
    }
    $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
    if (!is_string($signature)
        || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached(
            $signature,
            cloud_fixture_encode($envelope['payload']),
            $clientPublic
        )) {
        throw new RuntimeException('signed request signature is invalid');
    }
    $payload = $envelope['payload'];
    cloud_fixture_exact_keys($payload, [
        'action', 'command_index', 'command_phase', 'environment', 'format',
        'input', 'operation_id', 'request_id', 'site_id', 'target', 'tenant_id',
    ], 'control request payload');
    if (($payload['format'] ?? null) !== 'duo-cloud-preview-control-request/v1'
        || !in_array($payload['action'] ?? null, ['raw', 'wp'], true)
        || ($payload['environment'] ?? null) !== $expected['environment']
        || ($payload['tenant_id'] ?? null) !== $expected['tenant_id']
        || ($payload['site_id'] ?? null) !== $expected['site_id']
        || !is_int($payload['command_index'] ?? null)
        || $payload['command_index'] < 0
        || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', (string) ($payload['command_phase'] ?? '')) !== 1
        || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', (string) ($payload['operation_id'] ?? '')) !== 1
        || ($payload['request_id'] ?? null) !== cloud_fixture_request_id($payload)
        || !is_array($payload['input'] ?? null)
        || array_is_list($payload['input'])
        || !is_array($payload['target'] ?? null)
        || array_is_list($payload['target'])) {
        throw new RuntimeException('control request payload is malformed or foreign');
    }
    $target = $payload['target'];
    cloud_fixture_exact_keys($target, [
        'environment_identity', 'lease_generation', 'lease_id', 'mutation_generation',
        'mutation_id', 'mutation_owner', 'mutation_receipt_sha256',
        'ownership_receipt_sha256', 'resource_id',
    ], 'control request target binding');
    foreach ([
        'environment_identity', 'lease_generation', 'lease_id',
        'ownership_receipt_sha256', 'resource_id',
    ] as $key) {
        if (($target[$key] ?? null) !== ($expected['target'][$key] ?? null)) {
            throw new RuntimeException("control request target binding changed $key");
        }
    }
    $expectedOwner = 'duo-env-materialize-' . $payload['operation_id'];
    if (($target['mutation_owner'] ?? null) !== $expectedOwner) {
        throw new RuntimeException('control request is not bound to the held materialization fence owner');
    }
    if ($payload['action'] === 'raw') {
        cloud_fixture_exact_keys($payload['input'], ['script'], 'raw control input');
        if (!is_string($payload['input']['script'] ?? null) || $payload['input']['script'] === '') {
            throw new RuntimeException('raw control input is invalid');
        }
    } else {
        cloud_fixture_exact_keys($payload['input'], ['argv'], 'WP control input');
        $arguments = $payload['input']['argv'] ?? null;
        if (!is_array($arguments) || !array_is_list($arguments) || $arguments === []) {
            throw new RuntimeException('WP control input is invalid');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new RuntimeException('WP control argv is invalid');
            }
        }
    }
    return $payload;
}

/**
 * @param array<string,mixed> $envelope
 * @param array<string,mixed> $expected
 * @return array<string,mixed>
 */
function cloud_fixture_verify_lifecycle_request(
    array $envelope,
    array $expected,
    string $clientPublic
): array {
    cloud_fixture_exact_keys($envelope, ['format', 'key_id', 'payload', 'signature'], 'signed lifecycle request');
    if (($envelope['format'] ?? null) !== 'duo-cloud-preview-signed-envelope/v1'
        || ($envelope['key_id'] ?? null) !== $expected['request_key_id']
        || !is_array($envelope['payload'] ?? null)
        || array_is_list($envelope['payload'])) {
        throw new RuntimeException('signed lifecycle envelope is not bound to the paired site key');
    }
    $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
    if (!is_string($signature)
        || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached(
            $signature,
            cloud_fixture_encode($envelope['payload']),
            $clientPublic
        )) {
        throw new RuntimeException('signed lifecycle request signature is invalid');
    }
    $payload = $envelope['payload'];
    cloud_fixture_exact_keys($payload, [
        'action', 'environment', 'format', 'input', 'operation_id',
        'request_id', 'site_id', 'tenant_id',
    ], 'lifecycle request payload');
    if (($payload['format'] ?? null) !== 'duo-cloud-preview-lifecycle-request/v1'
        || !in_array($payload['action'] ?? null, [
            'capabilities', 'create', 'destroy', 'inspect', 'mutation-acquire',
            'mutation-read', 'mutation-release', 'repository-materialize',
            'repository-sync', 'sleep', 'snapshot-restore', 'ttl-read', 'ttl-set',
            'url-set', 'wake',
        ], true)
        || ($payload['environment'] ?? null) !== $expected['environment']
        || ($payload['tenant_id'] ?? null) !== $expected['tenant_id']
        || ($payload['site_id'] ?? null) !== $expected['site_id']
        || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', (string) ($payload['operation_id'] ?? '')) !== 1
        || ($payload['request_id'] ?? null) !== cloud_fixture_lifecycle_request_id($payload)
        || !is_array($payload['input'] ?? null)
        || (array_is_list($payload['input']) && $payload['input'] !== [])) {
        throw new RuntimeException('lifecycle request payload is malformed or foreign');
    }
    return $payload;
}

/**
 * @param array<string,mixed> $envelope
 * @param array<string,mixed> $expected
 * @return array<string,mixed>
 */
function cloud_fixture_verify_origin_request(
    array $envelope,
    array $expected,
    string $clientPublic
): array {
    cloud_fixture_exact_keys($envelope, ['format', 'key_id', 'payload', 'signature'], 'signed origin request');
    if (($envelope['format'] ?? null) !== 'duo-cloud-origin-controller-signed-envelope/v1'
        || ($envelope['key_id'] ?? null) !== $expected['request_key_id']
        || !is_array($envelope['payload'] ?? null)
        || array_is_list($envelope['payload'])) {
        throw new RuntimeException('signed origin envelope is not bound to the paired site key');
    }
    $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
    if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached(
            $signature,
            cloud_fixture_encode($envelope['payload']),
            $clientPublic
        )) {
        throw new RuntimeException('signed origin request signature is invalid');
    }
    $payload = $envelope['payload'];
    cloud_fixture_exact_keys($payload, [
        'action', 'format', 'input', 'operation_id', 'request_id', 'site_id', 'tenant_id',
    ], 'origin request payload');
    if (($payload['format'] ?? null) !== 'duo-cloud-origin-controller-request/v1'
        || !in_array($payload['action'] ?? null, [
            'chunk-read', 'demand-create', 'demand-status', 'manifest-read',
        ], true)
        || ($payload['tenant_id'] ?? null) !== $expected['tenant_id']
        || ($payload['site_id'] ?? null) !== $expected['site_id']
        || ($payload['request_id'] ?? null)
            !== cloud_fixture_origin_request_id($payload, $expected['request_key_id'])
        || !is_array($payload['input'] ?? null)
        || (array_is_list($payload['input']) && $payload['input'] !== [])) {
        throw new RuntimeException('origin request payload is malformed or foreign');
    }
    return $payload;
}

/** @param array<string,mixed> $payload @param array<string,mixed> $expected @return array<string,mixed> */
function cloud_fixture_origin_result(array $payload, array $expected): array {
    $universal = $expected['universal'] ?? null;
    if (!is_array($universal) || array_is_list($universal)) {
        throw new RuntimeException('origin route is unavailable outside the universal fixture');
    }
    $manifestPath = $universal['origin_manifest_path'] ?? null;
    $exportPath = $universal['origin_export_path'] ?? null;
    $manifest = is_string($manifestPath)
        ? json_decode((string) @file_get_contents($manifestPath), true)
        : ($universal['origin_manifest'] ?? null);
    $bytes = is_string($exportPath)
        ? @file_get_contents($exportPath)
        : base64_decode((string) ($universal['origin_export_base64'] ?? ''), true);
    if (!is_array($manifest) || array_is_list($manifest) || !is_string($bytes)) {
        throw new RuntimeException('universal origin fixture is malformed');
    }
    $commit = (string) ($manifest['expected_production_commit'] ?? '');
    $demandId = hash('sha256', "universal-demand\0$commit");
    $exportId = hash('sha256', "universal-export\0" . (string) ($manifest['manifest_sha256'] ?? ''));
    $demand = [
        'chunk_size' => 1048576,
        'demand_generation' => 1,
        'demand_id' => $demandId,
        'expires_at' => 2000000000,
        'expected_production_commit' => $commit,
        'format' => 'duo-cloud-origin-export-demand/v1',
        'nonce' => base64_encode(hash('sha256', 'universal-demand-nonce', true)),
        'retention_deadline' => 2000003600,
        'snapshot_mode' => 'portable-refresh',
    ];
    $action = $payload['action'];
    if ($action === 'demand-create') {
        if (($payload['input']['expected_production_commit'] ?? null) !== $commit) {
            throw new RuntimeException('origin demand requested another production commit');
        }
        return ['demand' => $demand];
    }
    $triggerPath = $universal['origin_trigger_path'] ?? null;
    $thresholdPath = $universal['origin_commit_threshold_path'] ?? null;
    $triggerRaw = is_string($triggerPath) && is_file($triggerPath)
        ? trim((string) @file_get_contents($triggerPath))
        : '0';
    $thresholdRaw = is_string($thresholdPath)
        ? trim((string) @file_get_contents($thresholdPath))
        : '';
    if (preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D', $triggerRaw) !== 1
        || preg_match('/\A[1-9][0-9]{0,3}\z/D', $thresholdRaw) !== 1) {
        throw new RuntimeException('universal origin drive counter is malformed');
    }
    $committed = (int) $triggerRaw >= (int) $thresholdRaw;
    if ($action === 'demand-status') {
        return [
            'poll_sequence' => $payload['input']['poll_sequence'] ?? null,
            'status' => [
                'demand_generation' => 1,
                'demand_id' => $demandId,
                'expected_production_commit' => $commit,
                'export_id' => $committed ? $exportId : null,
                'manifest_sha256' => $committed ? $manifest['manifest_sha256'] : null,
                'state' => $committed ? 'committed' : 'demanded',
            ],
        ];
    }
    if (!$committed) {
        throw new RuntimeException('origin export was read before the isolated production trigger');
    }
    if ($action === 'manifest-read') {
        return ['export_id' => $exportId, 'manifest' => $manifest];
    }
    if ($action === 'chunk-read') {
        $index = $payload['input']['chunk_index'] ?? null;
        if ($index !== 0 || count($manifest['chunks'] ?? []) !== 1) {
            throw new RuntimeException('universal origin fixture expects one exact chunk');
        }
        return [
            'chunk_base64' => base64_encode($bytes),
            'chunk_index' => 0,
            'chunk_sha256' => hash('sha256', $bytes),
            'chunk_size' => strlen($bytes),
            'export_id' => $exportId,
            'manifest_sha256' => $manifest['manifest_sha256'],
        ];
    }
    throw new RuntimeException("unsupported origin action '$action'");
}

/** @param array<string,mixed> $payload @param array<string,mixed> $expected @return array<string,mixed> */
function cloud_fixture_origin_response(
    array $payload,
    array $result,
    array $expected,
    string $serverSecret
): array {
    $responsePayload = [
        'action' => $payload['action'],
        'format' => 'duo-cloud-origin-controller-response/v1',
        'operation_id' => $payload['operation_id'],
        'request_sha256' => hash('sha256', cloud_fixture_encode($payload)),
        'result' => $result,
        'site_id' => $payload['site_id'],
        'status' => 'ok',
        'tenant_id' => $payload['tenant_id'],
    ];
    return [
        'format' => 'duo-cloud-origin-controller-signed-envelope/v1',
        'key_id' => $expected['response_key_id'],
        'payload' => $responsePayload,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            cloud_fixture_encode($responsePayload),
            $serverSecret
        )),
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $result
 * @param array<string,mixed> $expected
 * @return array<string,mixed>
 */
function cloud_fixture_lifecycle_response(
    array $payload,
    array $result,
    array $expected,
    string $serverSecret
): array {
    $responsePayload = [
        'action' => $payload['action'],
        'environment' => $payload['environment'],
        'format' => 'duo-cloud-preview-lifecycle-response/v1',
        'operation_id' => $payload['operation_id'],
        'provider' => ['id' => 'duo-cloud-preview', 'protocol' => 1],
        'request_sha256' => hash('sha256', cloud_fixture_encode($payload)),
        'result' => $result,
        'site_id' => $payload['site_id'],
        'status' => 'ok',
        'tenant_id' => $payload['tenant_id'],
    ];
    return [
        'format' => 'duo-cloud-preview-signed-envelope/v1',
        'key_id' => $expected['response_key_id'],
        'payload' => $responsePayload,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            cloud_fixture_encode($responsePayload),
            $serverSecret
        )),
    ];
}

/** @param array<string,mixed> $payload @return array<string,mixed> */
function cloud_fixture_signed_response(array $payload, array $expected, string $serverSecret): array {
    $result = cloud_fixture_control_result($payload, $expected);
    $responsePayload = [
        'action' => $payload['action'],
        'environment' => $payload['environment'],
        'format' => 'duo-cloud-preview-control-response/v1',
        'operation_id' => $payload['operation_id'],
        'request_sha256' => hash('sha256', cloud_fixture_encode($payload)),
        'result' => $result,
        'site_id' => $payload['site_id'],
        'target' => $payload['target'],
        'tenant_id' => $payload['tenant_id'],
    ];
    return [
        'format' => 'duo-cloud-preview-signed-envelope/v1',
        'key_id' => $expected['response_key_id'],
        'payload' => $responsePayload,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            cloud_fixture_encode($responsePayload),
            $serverSecret
        )),
    ];
}

/** @param array<string,mixed> $payload @param array<string,mixed> $expected @return array{exit:int,stderr:string,stdout:string} */
function cloud_fixture_control_result(array $payload, array $expected): array {
    $universal = $expected['universal'] ?? null;
    if (!is_array($universal) || array_is_list($universal)) {
        $stdout = $payload['action'] === 'wp'
            ? cloud_fixture_encode($expected['plan']) . "\n"
            : '';
        return ['exit' => 0, 'stderr' => '', 'stdout' => $stdout];
    }
    $statePath = (string) ($universal['target_state_path'] ?? '');
    $state = is_file($statePath)
        ? cloud_fixture_read_object($statePath, 'universal target state')
        : ['files' => []];
    $files = is_array($state['files'] ?? null) ? $state['files'] : [];
    $save = static function () use ($statePath, &$files): void {
        $bytes = cloud_fixture_encode(['files' => $files]) . "\n";
        if ($statePath === '' || file_put_contents($statePath, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('could not persist universal target state');
        }
    };
    $ok = static fn(string $stdout = ''): array =>
        ['exit' => 0, 'stderr' => '', 'stdout' => $stdout];
    $refuse = static fn(string $stderr): array =>
        ['exit' => 1, 'stderr' => $stderr, 'stdout' => ''];

    if ($payload['action'] === 'raw') {
        $script = (string) ($payload['input']['script'] ?? '');
        if (str_starts_with($script, 'mkdir -p ')) {
            return $ok();
        }
        if (str_starts_with($script, 'test -L ')) {
            return $refuse('not a symlink');
        }
        if (preg_match("/^test (-[efs]) '([^']+)'$/D", $script, $match) === 1) {
            $present = isset($files[$match[2]]);
            $nonempty = $present && is_string($files[$match[2]]) && $files[$match[2]] !== '';
            $passes = $match[1] === '-s' ? $nonempty : $present;
            return $passes ? $ok() : $refuse('absent');
        }
        if (str_starts_with($script, 'php -r ')) {
            if (preg_match("/ -- '([^']+)'$/D", $script, $match) !== 1
                || !isset($files[$match[1]])) {
                return $refuse('missing target file');
            }
            return $ok(hash('sha256', (string) $files[$match[1]]) . "\n");
        }
        if (preg_match("/^rm -f -- '([^']+)'$/D", $script, $match) === 1) {
            unset($files[$match[1]]);
            $save();
            return $ok();
        }
        if (preg_match("/^mv -f -- '([^']+)' '([^']+)'$/D", $script, $match) === 1) {
            if (!isset($files[$match[1]])) {
                return $refuse('missing move source');
            }
            $files[$match[2]] = $files[$match[1]];
            unset($files[$match[1]]);
            $save();
            return $ok();
        }
        return $ok();
    }

    $args = $payload['input']['argv'] ?? [];
    $duo = is_array($args) ? array_search('duo', $args, true) : false;
    $verb = is_int($duo) ? (string) ($args[$duo + 1] ?? '') : '';
    $find = static function (string $prefix) use ($args): ?string {
        foreach ($args as $argument) {
            if (is_string($argument) && str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
            }
        }
        return null;
    };
    if (($args[0] ?? null) === 'db' && ($args[1] ?? null) === 'export') {
        $path = (string) ($args[2] ?? '');
        if ($path === '') {
            return $refuse('checkpoint path is missing');
        }
        $files[$path] = "duo universal preview checkpoint\n";
        $save();
        return $ok("Exported to '$path'\n");
    }
    if ($verb === 'compile') {
        $out = $find('--out=');
        $artifactBytes = (string) ($universal['artifact_bytes'] ?? '');
        if (!is_string($out) || $out === '' || $artifactBytes === '') {
            return $refuse('compile fixture is incomplete');
        }
        $files[$out] = $artifactBytes;
        $save();
        return $ok(cloud_fixture_encode($universal['compile_summary'] ?? []) . "\n");
    }
    if ($verb === 'plan') {
        return $ok(cloud_fixture_encode($expected['plan']) . "\n");
    }
    if ($verb === 'apply') {
        $summary = $universal['compile_summary'] ?? [];
        return $ok(cloud_fixture_encode([
            'artifact' => [
                'hash' => $summary['artifact_hash'] ?? null,
                'revision' => $summary['revision_hash'] ?? null,
            ],
        ]) . "\n");
    }
    return $ok();
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $expected
 * @return array<string,mixed>
 */
function cloud_fixture_lifecycle_result(
    array $payload,
    array $expected,
    int &$mutationGeneration,
    string $fenceStatePath,
    array &$runtimeState,
    array &$resourceActions
): array {
    $action = $payload['action'];
    $input = $payload['input'];
    $resourceActions = [];
    $identity = [
        'environment_identity' => $expected['target']['environment_identity'],
        'lease_generation' => $expected['target']['lease_generation'],
        'lease_id' => $expected['target']['lease_id'],
        'ownership_receipt_sha256' => $expected['target']['ownership_receipt_sha256'],
        'resource_id' => $expected['target']['resource_id'],
        'url' => 'https://cloud-preview-fixture.example.test',
    ];
    $capabilities = [
        'environment.create', 'environment.destroy', 'environment.inspect',
        'environment.mutation.acquire', 'environment.mutation.read',
        'environment.mutation.release', 'environment.sleep', 'environment.ttl',
        'environment.ttl.read', 'environment.url.discover', 'environment.url.set',
        'environment.wake', 'operation.receipts', 'repository.materialize',
        'repository.sync', 'snapshot.set.restore',
    ];
    sort($capabilities, SORT_STRING);
    if ($action === 'capabilities') {
        $reviewedBase = [
            'format' => 'duo-reviewed-preview-base/v1',
            'image_digest' => 'sha256:' . hash('sha256', 'fixture reviewed image'),
            'platform_fingerprint_sha256' => hash('sha256', 'fixture reviewed platform'),
            'review_receipt_sha256' => hash('sha256', 'fixture review receipt'),
        ];
        $containmentBasis = [
            'egress_evidence' => 'host-nft-input-forward-default-deny-readback/v1',
            'format' => 'duo-reviewed-preview-base-containment/v1',
            'image_reference' => 'registry.example.test:5443/duo/wordpress@' . $reviewedBase['image_digest'],
            'reviewed_base' => $reviewedBase,
            'routing_evidence' => 'credential-free-route-authority-readback/v1',
            'runtime_configuration_sha256' => hash('sha256', 'fixture runtime configuration'),
            'seccomp_profile_sha256' => hash('sha256', 'fixture seccomp profile'),
            'secrets_evidence' => 'generation-private-files-readonly-mount-readback/v1',
            'storage_evidence' => 'dm-crypt-xfs-project-quota-exact-readback/v1',
        ];
        $containment = ['descriptor_sha256' => hash(
            'sha256',
            "duo-reviewed-preview-base-containment/v1\0" . cloud_fixture_encode($containmentBasis)
        )] + $containmentBasis;
        $remoteUrl = (string) ($expected['repository_remote_url']
            ?? 'https://git.example.test/duo/site.git');
        $repositoryBasis = [
            'credential_helper_sha256' => (string) ($expected['credential_helper_sha256']
                ?? hash('sha256', 'fixture credential helper')),
            'format' => 'duo-cloud-repository-authority/v1',
            'ref_prefix' => (string) ($expected['repository_ref_prefix']
                ?? 'refs/heads/duo-preview/'),
            'remote_url_sha256' => hash(
                'sha256',
                "duo-cloud-repository-remote-url/v1\0" . $remoteUrl
            ),
        ];
        $repositoryAuthority = ['descriptor_sha256' => hash(
            'sha256',
            "duo-cloud-repository-authority/v1\0" . cloud_fixture_encode($repositoryBasis)
        )] + $repositoryBasis;
        return [
            'capabilities' => $capabilities,
            'repository_authority' => $repositoryAuthority,
            'reviewed_base_containment' => $containment,
        ];
    }
    if ($action === 'create') {
        $runtimeState = [
            'execution' => 'running',
            'materialization_operation_id' => $payload['operation_id'],
            'presence' => 'present',
            'route' => 'routed',
        ];
        return $identity + ['presence' => 'present'];
    }
    if ($action === 'inspect') {
        return $identity + ['presence' => $runtimeState['presence']];
    }
    if ($action === 'mutation-acquire') {
        $mutationGeneration++;
    }
    $generation = $action === 'mutation-acquire'
        ? $mutationGeneration
        : (int) ($input['expected_mutation_generation'] ?? $mutationGeneration);
    $owner = (string) ($input['mutation_owner'] ?? $input['expected_mutation_owner'] ?? '');
    $mutationId = $generation === 1
        ? $expected['target']['mutation_id']
        : 'cloud-mutation-lease-' . str_pad((string) $generation, 4, '0', STR_PAD_LEFT);
    $heldReceipt = $generation === 1
        ? $expected['target']['mutation_receipt_sha256']
        : hash('sha256', 'cloud-mutation-held-generation-' . $generation);
    $mutation = $identity + [
        'mutation_generation' => $generation,
        'mutation_id' => $mutationId,
        'mutation_owner' => $owner,
        'mutation_receipt_sha256' => $heldReceipt,
        'state' => 'held',
    ];
    if ($action === 'mutation-release') {
        $mutation['mutation_receipt_sha256'] = hash(
            'sha256',
            'cloud-mutation-released-generation-' . $generation
        );
        $mutation['state'] = 'released';
        return $mutation;
    }
    if ($action === 'mutation-read' && is_file($fenceStatePath)) {
        $state = cloud_fixture_read_object($fenceStatePath, 'live provider fence state');
        $target = $state['target'] ?? null;
        if (!is_array($target) || array_is_list($target)
            || ($target['mutation_generation'] ?? null) !== $generation
            || ($target['mutation_id'] ?? null) !== $mutationId
            || ($target['mutation_owner'] ?? null) !== $owner
            || ($target['mutation_receipt_sha256'] ?? null)
                !== ($input['expected_mutation_receipt_sha256'] ?? null)
            || !in_array($state['state'] ?? null, ['held', 'released'], true)) {
            throw new RuntimeException('mutation read does not match the live fixture fence');
        }
        return $identity + $target + ['state' => $state['state']];
    }
    if (in_array($action, ['mutation-acquire', 'mutation-read'], true)) {
        return $mutation;
    }
    if ($action === 'snapshot-restore') {
        return $identity + ['snapshot_set_id' => (string) ($input['snapshot_set_id'] ?? '')];
    }
    if ($action === 'repository-sync') {
        return $identity + [
            'branch_commit' => (string) ($input['branch_commit'] ?? ''),
            'branch_ref' => (string) ($input['branch_ref'] ?? ''),
            'candidate_publication_receipt_sha256' =>
                (string) ($input['candidate_publication_receipt_sha256'] ?? ''),
            'repository_authority_sha256' =>
                (string) ($input['repository_authority_sha256'] ?? ''),
            'repository_sync_receipt_sha256' => hash(
                'sha256',
                'cloud-repository-sync-' . (string) ($input['branch_ref'] ?? '')
            ),
        ];
    }
    if ($action === 'repository-materialize') {
        return $identity + [
            'branch_commit' => (string) ($input['branch_commit'] ?? ''),
            'repository_receipt_sha256' => hash('sha256', 'cloud-repository'),
        ];
    }
    if ($action === 'url-set') {
        return $identity;
    }
    if (in_array($action, ['sleep', 'wake'], true)) {
        $expectedKeys = [
            'expected_environment_identity', 'expected_lease_generation',
            'expected_lease_id', 'expected_mutation_generation',
            'expected_mutation_id', 'expected_mutation_owner',
            'expected_mutation_receipt_sha256',
            'expected_ownership_receipt_sha256', 'expected_resource_id',
        ];
        cloud_fixture_exact_keys($input, $expectedKeys, "$action lifecycle input");
        $fenceState = cloud_fixture_read_object($fenceStatePath, 'live provider fence state');
        $fenceTarget = $fenceState['target'] ?? null;
        if (($fenceState['state'] ?? null) !== 'held'
            || !is_array($fenceTarget) || array_is_list($fenceTarget)
            || ($input['expected_environment_identity'] ?? null) !== $identity['environment_identity']
            || ($input['expected_lease_generation'] ?? null) !== $identity['lease_generation']
            || ($input['expected_lease_id'] ?? null) !== $identity['lease_id']
            || ($input['expected_ownership_receipt_sha256'] ?? null)
                !== $identity['ownership_receipt_sha256']
            || ($input['expected_resource_id'] ?? null) !== $identity['resource_id']
            || ($input['expected_mutation_generation'] ?? null)
                !== ($fenceTarget['mutation_generation'] ?? null)
            || ($input['expected_mutation_id'] ?? null) !== ($fenceTarget['mutation_id'] ?? null)
            || ($input['expected_mutation_owner'] ?? null) !== ($fenceTarget['mutation_owner'] ?? null)
            || ($input['expected_mutation_receipt_sha256'] ?? null)
                !== ($fenceTarget['mutation_receipt_sha256'] ?? null)
            || ($input['expected_mutation_owner'] ?? null)
                !== 'duo-env-sleep-' . ($runtimeState['materialization_operation_id'] ?? '')) {
            throw new RuntimeException("$action does not name the live lineage-bound sleep fence");
        }
        if ($action === 'sleep') {
            if ($runtimeState['presence'] !== 'present'
                || $runtimeState['route'] !== 'routed'
                || $runtimeState['execution'] !== 'running') {
                throw new RuntimeException('sleep requires a routed running preview generation');
            }
            $runtimeState['route'] = 'absent';
            $resourceActions[] = 'route-revoke';
            $runtimeState['execution'] = 'revoked';
            $resourceActions[] = 'execution-revoke';
            return $identity + ['sleep_state' => 'asleep'];
        }
        if ($runtimeState['presence'] !== 'present'
            || $runtimeState['route'] !== 'absent'
            || $runtimeState['execution'] !== 'revoked') {
            throw new RuntimeException('wake requires an asleep preview generation');
        }
        $runtimeState['execution'] = 'running';
        $resourceActions[] = 'execution-resume';
        $runtimeState['route'] = 'routed';
        $resourceActions[] = 'route-restore';
        return $identity + ['sleep_state' => 'awake'];
    }
    if (in_array($action, ['ttl-set', 'ttl-read'], true)) {
        return $identity + [
            'expires_at' => '2030-01-02T03:04:05Z',
            'ttl_generation' => 1,
            'ttl_lease_id' => 'cloud-ttl-lease-0001',
            'ttl_receipt_sha256' => hash('sha256', 'cloud-ttl'),
            'ttl_state' => 'active',
        ];
    }
    if ($action === 'destroy') {
        if ($runtimeState['presence'] === 'present') {
            $expectedKeys = [
                'compare_and_reap', 'expected_environment_identity',
                'expected_lease_generation', 'expected_lease_id',
                'expected_mutation_generation', 'expected_mutation_id',
                'expected_mutation_owner', 'expected_mutation_receipt_sha256',
                'expected_ownership_receipt_sha256', 'expected_resource_id',
            ];
            cloud_fixture_exact_keys($input, $expectedKeys, 'destroy lifecycle input');
            $fenceState = cloud_fixture_read_object($fenceStatePath, 'live provider fence state');
            $fenceTarget = $fenceState['target'] ?? null;
            if (($input['compare_and_reap'] ?? null) !== true
                || ($fenceState['state'] ?? null) !== 'held'
                || !is_array($fenceTarget) || array_is_list($fenceTarget)
                || ($input['expected_environment_identity'] ?? null)
                    !== $identity['environment_identity']
                || ($input['expected_lease_generation'] ?? null) !== $identity['lease_generation']
                || ($input['expected_lease_id'] ?? null) !== $identity['lease_id']
                || ($input['expected_ownership_receipt_sha256'] ?? null)
                    !== $identity['ownership_receipt_sha256']
                || ($input['expected_resource_id'] ?? null) !== $identity['resource_id']
                || ($input['expected_mutation_generation'] ?? null)
                    !== ($fenceTarget['mutation_generation'] ?? null)
                || ($input['expected_mutation_id'] ?? null)
                    !== ($fenceTarget['mutation_id'] ?? null)
                || ($input['expected_mutation_owner'] ?? null)
                    !== ($fenceTarget['mutation_owner'] ?? null)
                || ($input['expected_mutation_receipt_sha256'] ?? null)
                    !== ($fenceTarget['mutation_receipt_sha256'] ?? null)) {
                throw new RuntimeException('destroy does not name the exact live held mutation fence');
            }
            if ($runtimeState['route'] === 'routed') {
                $runtimeState['route'] = 'absent';
                $resourceActions[] = 'route-revoke';
            } else {
                $resourceActions[] = 'route-verify-absent';
            }
            if ($runtimeState['execution'] === 'running') {
                $runtimeState['execution'] = 'revoked';
                $resourceActions[] = 'execution-revoke';
            } else {
                $resourceActions[] = 'execution-verify-revoked';
            }
            $runtimeState['presence'] = 'absent';
            $resourceActions[] = 'state-delete';
        }
        return [
            'absence_proof_sha256' => hash('sha256', 'cloud-absence'),
            'disposition' => 'destroyed',
            'environment_identity' => $identity['environment_identity'],
            'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'],
            'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ];
    }
    throw new RuntimeException("unsupported lifecycle action '$action'");
}

$expected = cloud_fixture_read_object($expectedPath, 'cloud control expectations');
$clientPublic = cloud_fixture_key($clientPublicPath, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'client public key');
$serverSecret = cloud_fixture_key($serverSecretPath, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'server secret key');
$errno = 0;
$error = '';
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
    throw new RuntimeException("could not start loopback endpoint: $error ($errno)");
}
$address = stream_socket_get_name($server, false);
if (!is_string($address) || preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) !== 1) {
    throw new RuntimeException('loopback endpoint did not receive a concrete address');
}
$readyBytes = 'http://' . $address . "/v1/preview/control\n";
if (file_put_contents($readyPath, $readyBytes, LOCK_EX) !== strlen($readyBytes)) {
    throw new RuntimeException('could not publish loopback endpoint readiness');
}

$requestReceipts = [];
$lifecycleReceipts = [];
$originReceipts = [];
$mutationGeneration = 0;
$runtimeState = [
    'execution' => 'absent',
    'materialization_operation_id' => null,
    'presence' => 'absent',
    'route' => 'absent',
];
while (!is_file($stopPath)) {
    $connection = @stream_socket_accept($server, 1);
    if (!is_resource($connection)) {
        continue;
    }
    try {
        $request = cloud_fixture_http_request($connection);
        if ($request['path'] === '/v1/origin/controller/export') {
            $decoded = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)
                || cloud_fixture_encode($decoded) . "\n" !== $request['body']) {
                throw new RuntimeException('origin request is not canonical JSON');
            }
            $payload = cloud_fixture_verify_origin_request($decoded, $expected, $clientPublic);
            $requestKey = $decoded['key_id'] . "\0" . $payload['request_id'];
            $requestHash = hash('sha256', cloud_fixture_encode($payload));
            $cached = $originReceipts[$requestKey] ?? null;
            if (is_array($cached)) {
                if (!hash_equals((string) ($cached['request_sha256'] ?? ''), $requestHash)) {
                    throw new RuntimeException('origin request id was replayed with changed signed bytes');
                }
                cloud_fixture_http_response($connection, 200, (string) $cached['response']);
                continue;
            }
            $result = cloud_fixture_origin_result($payload, $expected);
            $response = cloud_fixture_encode(cloud_fixture_origin_response(
                $payload,
                $result,
                $expected,
                $serverSecret
            )) . "\n";
            $originReceipts[$requestKey] = [
                'request_sha256' => $requestHash,
                'response' => $response,
            ];
            cloud_fixture_log($logPath, [
                'action' => $payload['action'],
                'kind' => 'origin-controller',
                'operation_id' => $payload['operation_id'],
            ]);
            cloud_fixture_http_response($connection, 200, $response);
            continue;
        }
        if ($request['path'] === '/v1/preview/lifecycle') {
            $decoded = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)
                || cloud_fixture_encode($decoded) . "\n" !== $request['body']) {
                throw new RuntimeException('lifecycle request is not canonical JSON');
            }
            $payload = cloud_fixture_verify_lifecycle_request($decoded, $expected, $clientPublic);
            $requestKey = $decoded['key_id'] . "\0" . $payload['request_id'];
            $requestHash = hash('sha256', cloud_fixture_encode($payload));
            $cached = $lifecycleReceipts[$requestKey] ?? null;
            if (is_array($cached)) {
                if (!hash_equals((string) ($cached['request_sha256'] ?? ''), $requestHash)) {
                    throw new RuntimeException('lifecycle request id was replayed with changed signed bytes');
                }
                cloud_fixture_log($logPath, [
                    'action' => $payload['action'],
                    'kind' => 'provider',
                    'operation_id' => $payload['operation_id'],
                    'replayed' => true,
                    'role' => 'target',
                ]);
                cloud_fixture_http_response($connection, 200, (string) $cached['response']);
                continue;
            }
            $resourceActions = [];
            $result = cloud_fixture_lifecycle_result(
                $payload,
                $expected,
                $mutationGeneration,
                $fenceStatePath,
                $runtimeState,
                $resourceActions
            );
            $lifecycleMode = trim((string) @file_get_contents($responseModePath));
            if ($lifecycleMode === 'bad-containment-hash'
                && $payload['action'] === 'capabilities'
                && is_array($result['reviewed_base_containment'] ?? null)) {
                $result['reviewed_base_containment']['descriptor_sha256'] = str_repeat('0', 64);
            }
            if ($lifecycleMode === 'repository-sync-foreign-commit'
                && $payload['action'] === 'repository-sync') {
                $result['branch_commit'] = str_repeat('0', 40);
            }
            if ($lifecycleMode === 'externally-reaped'
                && $payload['action'] === 'inspect') {
                $result['presence'] = 'absent';
            }
            if (in_array($payload['action'], ['mutation-acquire', 'mutation-release'], true)) {
                $target = [
                    'environment_identity' => $result['environment_identity'],
                    'lease_generation' => $result['lease_generation'],
                    'lease_id' => $result['lease_id'],
                    'mutation_generation' => $result['mutation_generation'],
                    'mutation_id' => $result['mutation_id'],
                    'mutation_owner' => $result['mutation_owner'],
                    'mutation_receipt_sha256' => $result['mutation_receipt_sha256'],
                    'ownership_receipt_sha256' => $result['ownership_receipt_sha256'],
                    'resource_id' => $result['resource_id'],
                ];
                $state = [
                    'operation_id' => $payload['operation_id'],
                    'state' => $result['state'],
                    'target' => $target,
                ];
                file_put_contents($fenceStatePath, cloud_fixture_encode($state) . "\n", LOCK_EX);
            }
            if ($payload['action'] === 'destroy') {
                file_put_contents(
                    $fenceStatePath,
                    cloud_fixture_encode(['state' => 'absent']) . "\n",
                    LOCK_EX
                );
            }
            $responseEnvelope = cloud_fixture_lifecycle_response(
                $payload,
                $result,
                $expected,
                $serverSecret
            );
            $response = cloud_fixture_encode($responseEnvelope) . "\n";
            $lifecycleReceipts[$requestKey] = [
                'request_sha256' => $requestHash,
                'response' => $response,
            ];
            cloud_fixture_log($logPath, [
                'action' => $payload['action'],
                'kind' => 'provider',
                'mutation_generation' => $result['mutation_generation']
                    ?? $payload['input']['expected_mutation_generation'] ?? null,
                'mutation_id' => $result['mutation_id']
                    ?? $payload['input']['expected_mutation_id'] ?? null,
                'mutation_owner' => $result['mutation_owner']
                    ?? $payload['input']['expected_mutation_owner']
                    ?? $payload['input']['mutation_owner'] ?? null,
                'mutation_receipt_sha256' => $result['mutation_receipt_sha256']
                    ?? $payload['input']['expected_mutation_receipt_sha256'] ?? null,
                'operation_id' => $payload['operation_id'],
                'replayed' => false,
                'resource_actions' => $resourceActions,
                'runtime_state' => $runtimeState,
                'role' => 'target',
            ]);
            cloud_fixture_http_response($connection, 200, $response);
            continue;
        }
        if ($request['path'] !== '/v1/preview/control') {
            throw new RuntimeException('control request used the wrong path');
        }
        $decoded = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)
            || cloud_fixture_encode($decoded) . "\n" !== $request['body']) {
            throw new RuntimeException('control request is not canonical JSON');
        }
        $payload = cloud_fixture_verify_request($decoded, $expected, $clientPublic);
        $fenceState = cloud_fixture_read_object($fenceStatePath, 'live provider fence state');
        if (($fenceState['state'] ?? null) !== 'held'
            || ($fenceState['operation_id'] ?? null) !== $payload['operation_id']
            || !is_array($fenceState['target'] ?? null)
            || cloud_fixture_encode($fenceState['target']) !== cloud_fixture_encode($payload['target'])) {
            throw new RuntimeException('control request does not name the live held provider fence');
        }
        $requestKey = $decoded['key_id'] . "\0" . $payload['request_id'];
        $requestHash = hash('sha256', cloud_fixture_encode($payload));
        $cached = $requestReceipts[$requestKey] ?? null;
        if (is_array($cached)) {
            if (!hash_equals((string) ($cached['request_sha256'] ?? ''), $requestHash)) {
                throw new RuntimeException('control request id was replayed with changed signed bytes');
            }
            cloud_fixture_log($logPath, [
                'accepted' => true,
                'executed' => false,
                'kind' => 'control',
                'payload' => $payload,
                'replayed' => true,
            ]);
            cloud_fixture_http_response($connection, 200, (string) $cached['response']);
            continue;
        }
        $responseEnvelope = cloud_fixture_signed_response($payload, $expected, $serverSecret);
        $responseMode = trim((string) @file_get_contents($responseModePath));
        if ($responseMode === 'wrong-key') {
            $responseEnvelope['key_id'] = 'foreign-control-key-fixture';
        } elseif ($responseMode === 'wrong-signature') {
            $signature = base64_decode((string) $responseEnvelope['signature'], true);
            if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                throw new RuntimeException('fixture could not decode its response signature');
            }
            $signature[0] = chr(ord($signature[0]) ^ 1);
            $responseEnvelope['signature'] = base64_encode($signature);
        } elseif (in_array($responseMode, [
            'wrong-action', 'wrong-environment', 'wrong-operation', 'wrong-request-hash',
            'wrong-site', 'wrong-target', 'wrong-tenant',
        ], true)) {
            $responsePayload = $responseEnvelope['payload'];
            if ($responseMode === 'wrong-action') {
                $responsePayload['action'] = $payload['action'] === 'raw' ? 'wp' : 'raw';
            } elseif ($responseMode === 'wrong-environment') {
                $responsePayload['environment'] = 'foreign-preview';
            } elseif ($responseMode === 'wrong-operation') {
                $responsePayload['operation_id'] = '20000101-000000-' . str_repeat('b', 24);
            } elseif ($responseMode === 'wrong-request-hash') {
                $responsePayload['request_sha256'] = hash('sha256', 'foreign-control-request');
            } elseif ($responseMode === 'wrong-site') {
                $responsePayload['site_id'] = 'site-foreign-0001';
            } elseif ($responseMode === 'wrong-target') {
                $responsePayload['target']['resource_id'] = 'cloud-preview-slot-foreign';
            } elseif ($responseMode === 'wrong-tenant') {
                $responsePayload['tenant_id'] = 'tenant-foreign-0001';
            }
            $responseEnvelope['payload'] = $responsePayload;
            $responseEnvelope['signature'] = base64_encode(sodium_crypto_sign_detached(
                cloud_fixture_encode($responsePayload),
                $serverSecret
            ));
        } elseif ($responseMode === 'stall-response') {
            cloud_fixture_log($logPath, [
                'accepted' => true,
                'executed' => false,
                'kind' => 'control',
                'payload' => $payload,
                'replayed' => false,
            ]);
            cloud_fixture_stalled_response($connection);
            usleep(2500000);
            continue;
        } elseif ($responseMode === 'target-apply-stderr-secret') {
            $args = $payload['input']['argv'] ?? [];
            $duoAt = is_array($args) ? array_search('duo', $args, true) : false;
            $verb = is_int($duoAt) ? ($args[$duoAt + 1] ?? null) : null;
            if ($payload['action'] === 'wp' && $verb === 'apply') {
                $responsePayload = $responseEnvelope['payload'];
                $responsePayload['result'] = [
                    'exit' => 17,
                    'stderr' => 'universal-private-target-stderr-sentinel',
                    'stdout' => '',
                ];
                $responseEnvelope['payload'] = $responsePayload;
                $responseEnvelope['signature'] = base64_encode(sodium_crypto_sign_detached(
                    cloud_fixture_encode($responsePayload),
                    $serverSecret
                ));
            } else {
                // Only the frozen apply is the fault boundary. Earlier target
                // commands remain replayable so this proves the public wrapper
                // around the real promotion path, not a host-side preflight.
                $responseMode = '';
            }
        } elseif ($responseMode === 'target-stream-stdout-secret') {
            $args = $payload['input']['argv'] ?? [];
            $duoAt = is_array($args) ? array_search('duo', $args, true) : false;
            $verb = is_int($duoAt) ? ($args[$duoAt + 1] ?? null) : null;
            if ($payload['action'] === 'wp'
                && $verb === 'deploy'
                && in_array('--lifecycle-phase=retire', $args, true)) {
                $responsePayload = $responseEnvelope['payload'];
                $responsePayload['result'] = [
                    'exit' => 23,
                    'stderr' => '',
                    'stdout' => 'universal-private-target-stdout-sentinel',
                ];
                $responseEnvelope['payload'] = $responsePayload;
                $responseEnvelope['signature'] = base64_encode(sodium_crypto_sign_detached(
                    cloud_fixture_encode($responsePayload),
                    $serverSecret
                ));
            } else {
                $responseMode = '';
            }
        } elseif ($responseMode !== '') {
            throw new RuntimeException('unknown fixture response mode');
        }
        cloud_fixture_log($logPath, [
            'accepted' => true,
            'executed' => $responseMode === '',
            'kind' => 'control',
            'payload' => $payload,
            'replayed' => false,
        ]);
        $response = cloud_fixture_encode($responseEnvelope) . "\n";
        if ($responseMode === '') {
            $requestReceipts[$requestKey] = [
                'request_sha256' => $requestHash,
                'response' => $response,
            ];
        }
        cloud_fixture_http_response($connection, 200, $response);
    } catch (Throwable $error) {
        cloud_fixture_log($logPath, [
            'accepted' => false,
            'error_sha256' => hash('sha256', $error->getMessage()),
            'executed' => false,
            'kind' => 'control',
        ]);
        $body = cloud_fixture_encode([
            'format' => 'duo-cloud-preview-control-refusal/v1',
            'status' => 'refused',
        ]) . "\n";
        cloud_fixture_http_response($connection, 403, $body);
    } finally {
        fclose($connection);
    }
}

fclose($server);
sodium_memzero($serverSecret);
