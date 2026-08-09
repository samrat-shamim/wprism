<?php
// DUO-3324: offline provider/journal safety contract for branch environments.
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Registry.php';
require_once __DIR__ . '/../../cli/src/EnvironmentLifecycle.php';

use Duo\Orchestrator\CommandEnvironmentProvider;
use Duo\Orchestrator\EnvironmentLifecycleCanon;
use Duo\Orchestrator\EnvironmentLifecycleJournal;
use Duo\Orchestrator\EnvironmentProviderCapability;
use Duo\Orchestrator\Registry;

function el_fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function el_ok(bool $condition, string $message): void {
    if (!$condition) el_fail($message);
    echo "ok: $message\n";
}

function el_throws(callable $call, string $needle, string $message): void {
    try {
        $call();
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), $needle)) {
            el_fail($message . ' (unexpected diagnostic: ' . $e->getMessage() . ')');
        }
        el_ok(true, $message . ' (diagnostic)');
        return;
    }
    el_fail($message . ' (no refusal)');
}

function el_remove(string $path): void {
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . '/' . $item;
        is_dir($child) && !is_link($child) ? el_remove($child) : @unlink($child);
    }
    @rmdir($path);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function el_cli(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../cli/duo'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) el_fail('could not start public duo CLI');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$tmp = sys_get_temp_dir() . '/duo-environment-lifecycle-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) el_fail('could not create fixture root');

try {
    $providerScript = $tmp . '/provider.php';
    $fixture = <<<'PHP'
<?php
declare(strict_types=1);
$mode = $argv[1] ?? 'ok';
$log = $argv[2] ?? '';
$raw = (string) stream_get_contents(STDIN);
$request = json_decode($raw, true);
if ($log !== '') file_put_contents($log, $raw, FILE_APPEND | LOCK_EX);
if ($mode === 'fail') { fwrite(STDERR, "SUPER-SECRET-provider-diagnostic\n"); exit(7); }
function canon(mixed $value): string {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = json_decode(canon($child), true);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
$h = static fn(string $v): string => hash('sha256', $v);
$identity = [
    'environment_identity' => 'environment-identity-0001',
    'lease_generation' => 3,
    'lease_id' => 'lease-identity-0001',
    'ownership_receipt_sha256' => $h('owner'),
    'resource_id' => 'resource-identity-0001',
    'url' => 'https://branch.example.test',
];
$action = (string) ($request['action'] ?? '');
$result = match ($action) {
    'capabilities' => ['capabilities' => [
        'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
        'environment.inspect', 'environment.ttl', 'environment.url.discover', 'environment.url.set',
        'operation.receipts', 'repository.materialize', 'snapshot.set.create',
        'snapshot.set.read', 'snapshot.set.restore',
    ]],
    'inspect', 'attach', 'create' => $identity + ['presence' => 'present'],
    'snapshot-create' => [
        'database_sha256' => $h('database'),
        'media_sha256' => $h('media'),
        'retention_receipt_sha256' => $h('retention'),
        'semantic_snapshot_sha256' => $h('semantic'),
        'snapshot_set_id' => 'snapshot-set-0001',
        'source_identity' => 'environment-identity-0001',
    ],
    'snapshot-restore' => $identity + ['snapshot_set_id' => 'snapshot-set-0001'],
    'repository-materialize' => $identity + [
        'branch_commit' => str_repeat('a', 40),
        'repository_receipt_sha256' => $h('repository'),
    ],
    'url-set' => $identity,
    'ttl-set' => $identity + [
        'expires_at' => '2030-01-02T03:04:05Z',
        'ttl_generation' => 4,
        'ttl_lease_id' => 'ttl-lease-identity-0001',
    ],
    'destroy' => [
        'absence_proof_sha256' => $h('absence'),
        'disposition' => 'destroyed',
        'environment_identity' => $identity['environment_identity'],
        'lease_generation' => $identity['lease_generation'],
        'lease_id' => $identity['lease_id'],
        'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'resource_id' => $identity['resource_id'],
    ],
    'detach' => [
        'absence_proof_sha256' => $h('absence'),
        'disposition' => 'detached',
        'environment_identity' => $identity['environment_identity'],
        'lease_generation' => $identity['lease_generation'],
        'lease_id' => $identity['lease_id'],
        'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'resource_id' => $identity['resource_id'],
    ],
    default => [],
};
if ($mode === 'bad-capability' && $action === 'capabilities') $result['capabilities'][] = 'host.magic';
$response = [
    'action' => $mode === 'mismatch' ? 'destroy' : $action,
    'format' => 'duo-branch-environment-provider-response/v1',
    'operation_id' => (string) ($request['operation_id'] ?? ''),
    'provider' => ['id' => 'fixture-provider', 'protocol' => 1],
    'result' => $result,
    'status' => 'ok',
];
$bytes = canon($response);
echo $mode === 'noncanonical' ? json_encode(json_decode($bytes, true), JSON_PRETTY_PRINT) . "\n" : $bytes . "\n";
PHP;
    file_put_contents($providerScript, $fixture);
    $operation = '20300102-030405-' . str_repeat('a', 24);
    $config = static function (string $mode = 'ok', string $log = '') use ($providerScript): array {
        $command = [PHP_BINARY, $providerScript, $mode];
        if ($log !== '') $command[] = $log;
        return [
            '_machine_local' => true,
            'environment_provider' => ['command' => $command, 'timeout_seconds' => 5],
        ];
    };

    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('checked-in', [
            '_machine_local' => false,
            'environment_provider' => ['command' => [PHP_BINARY, $providerScript, 'ok'], 'timeout_seconds' => 5],
        ]),
        'allowed only in .duo-envs.json',
        'checked-in provider configuration is never privileged'
    );
    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('relative', [
            '_machine_local' => true,
            'environment_provider' => ['command' => ['provider'], 'timeout_seconds' => 5],
        ]),
        'executable must be absolute',
        'provider execution never uses PATH or a shell'
    );
    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('unknown-field', [
            '_machine_local' => true,
            'environment_provider' => ['command' => [PHP_BINARY], 'timeout_seconds' => 5, 'token' => 'secret'],
        ]),
        'missing or unknown fields',
        'provider configuration has a closed shape'
    );

    $log = $tmp . '/requests.log';
    $provider = CommandEnvironmentProvider::fromEnvironment('branch', $config('ok', $log));
    $report = $provider->capabilities($operation);
    $body = $report->toArray();
    el_ok($body['format'] === 'duo-branch-environment-capabilities/v1', 'capability evidence is versioned');
    el_ok(preg_match('/^sha256:[a-f0-9]{64}$/D', $report->digest()) === 1, 'capability evidence is digest-bound');
    $report->require([
        EnvironmentProviderCapability::ENVIRONMENT_ATTACH,
        EnvironmentProviderCapability::SNAPSHOT_SET_RESTORE,
    ], 'attach a target');
    el_throws(
        static fn() => $report->require(['environment.not-real'], 'guess'),
        'unknown environment-provider requirement',
        'unknown requirements fail closed'
    );
    $attached = $provider->perform('attach', $operation, ['intent_sha256' => hash('sha256', 'intent')]);
    el_ok($attached['environment_identity'] === 'environment-identity-0001', 'structured attach identity is returned');
    el_ok(($attached['_provider']['id'] ?? null) === 'fixture-provider', 'provider identity remains bound to evidence');
    $requestLines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    el_ok(is_array($requestLines) && count($requestLines) === 2, 'capability and attach used exactly two provider calls');
    foreach ($requestLines as $line) {
        $decoded = json_decode($line, true);
        el_ok(is_array($decoded) && EnvironmentLifecycleCanon::encode($decoded) === $line, 'provider request is canonical JSON');
    }

    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('bad-cap', $config('bad-capability'))->capabilities($operation),
        'unknown capability',
        'provider capability vocabulary is closed'
    );
    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('mismatch', $config('mismatch'))->perform('attach', $operation, []),
        'not bound to the request',
        'response action and operation identity are request-bound'
    );
    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('pretty', $config('noncanonical'))->perform('attach', $operation, []),
        'noncanonical evidence',
        'provider response must be canonical bytes'
    );
    try {
        CommandEnvironmentProvider::fromEnvironment('failed', $config('fail'))->perform('attach', $operation, []);
        el_fail('provider failure was accepted');
    } catch (Throwable $e) {
        el_ok(!str_contains($e->getMessage(), 'SUPER-SECRET'), 'provider failure output is redacted');
    }

    $journal = new EnvironmentLifecycleJournal($tmp . '/journal');
    $run = [
        'branch_commit' => str_repeat('b', 40),
        'branch_ref' => 'feature/test',
        'created_at' => '2030-01-02T03:04:05Z',
        'format' => EnvironmentLifecycleJournal::RUN_FORMAT,
        'intent_sha256' => hash('sha256', 'journal-intent'),
        'mode' => 'create',
        'operation_id' => $operation,
        'source_environment' => 'production',
        'target_environment' => 'branch',
        'ttl_seconds' => 3600,
    ];
    $journal->start($operation, $run);
    $journal->append($operation, 'target-acquired', [
        'environment_identity' => 'environment-identity-0001',
        'lease_generation' => 3,
        'resource_id' => 'resource-identity-0001',
    ]);
    $events = $journal->events($operation);
    el_ok(count($events) === 2 && $events[1]['previous_event_sha256'] !== str_repeat('0', 64), 'journal is append-only and hash-chained');
    $latest = $journal->latestForTarget('branch');
    el_ok(($latest['operation_id'] ?? null) === $operation, 'journal resolves the latest exact target operation');
    el_throws(static fn() => $journal->start($operation, $run), 'already exists', 'run records are immutable');

    $firstEvent = $journal->runDir($operation) . '/events/0001-prepared.json';
    $tampered = json_decode((string) file_get_contents($firstEvent), true);
    $tampered['data']['intent_sha256'] = hash('sha256', 'tampered');
    file_put_contents($firstEvent, EnvironmentLifecycleCanon::encode($tampered) . "\n");
    el_throws(static fn() => $journal->events($operation), 'broken event chain', 'journal tampering breaks the next event hash');

    $registryRoot = $tmp . '/registry';
    mkdir($registryRoot, 0700, true);
    file_put_contents($registryRoot . '/site.duo.json', json_encode(['envs' => [
        'checked' => ['transport' => 'local', 'wp_path' => '/wp', 'repo_path' => '/repo', '_machine_local' => true],
    ]]));
    file_put_contents($registryRoot . '/overlay.json', json_encode(['envs' => [
        'local' => ['transport' => 'local', 'wp_path' => '/wp', 'repo_path' => '/repo'],
    ]]));
    $envs = Registry::load($registryRoot . '/overlay.json', $registryRoot);
    el_ok(($envs['checked']['_machine_local'] ?? true) === false, 'checked-in config cannot forge machine-local provenance');
    el_ok(($envs['local']['_machine_local'] ?? false) === true, 'overlay config receives loader-owned machine-local provenance');

    $missing = el_cli(['env', 'materialize', 'branch', '--from=production']);
    el_ok($missing['exit'] !== 0 && str_contains($missing['stderr'], 'requires exactly --from')
        && !str_contains($missing['stderr'], 'environment_provider'), 'public parser refuses incomplete intent before registry/provider access');
    $unknown = el_cli(['env', 'materialize', 'branch', '--from=production', '--branch=feature', '--receipt=forged']);
    el_ok($unknown['exit'] !== 0 && str_contains($unknown['stderr'], "unsupported flag '--receipt=forged'")
        && !str_contains($unknown['stderr'], 'environment_provider'), 'caller cannot inject receipt/lease/artifact fields');
    $reapFlags = el_cli(['env', 'reap', 'branch', '--force']);
    el_ok($reapFlags['exit'] !== 0 && str_contains($reapFlags['stderr'], 'accepts only optional --format=json'), 'public reap has no force or name-only destruction escape hatch');
    $help = el_cli(['--help']);
    el_ok($help['exit'] === 0 && str_contains($help['stdout'], 'duo env materialize') && str_contains($help['stdout'], 'duo env reap'), 'public help documents materialize and exact reap');

    echo "PASS: environment lifecycle provider and journal regression\n";
} finally {
    el_remove($tmp);
}
