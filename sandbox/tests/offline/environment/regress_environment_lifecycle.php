<?php
// DUO-3324: offline provider/journal safety contract for branch environments.
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Environment/Registry.php';
require_once __DIR__ . '/../../../../cli/src/Environment/EnvironmentLifecycle.php';

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

function el_tree_contains(string $root, string $needle): bool {
    if (!is_dir($root)) return false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) continue;
        $bytes = file_get_contents($item->getPathname());
        if (is_string($bytes) && str_contains($bytes, $needle)) return true;
    }
    return false;
}

/** @return array{exit:int,stdout:string,stderr:string} */
function el_process(array $argv, ?string $cwd = null): array {
    $process = proc_open($argv, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) el_fail('could not start child process');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array{exit:int,stdout:string,stderr:string} */
function el_cli(array $args, ?string $cwd = null): array {
    return el_process(array_merge([PHP_BINARY, __DIR__ . '/../../../../cli/duo'], $args), $cwd);
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
$secretUrls = [
    'credential-url' => 'https://provider-user:provider-password@branch.example.test/',
    'signed-query-url' => 'https://branch.example.test/?X-Amz-Signature=provider-token',
    'fragment-secret-url' => 'https://branch.example.test/#provider-token',
];
if (isset($secretUrls[$mode])) $identity['url'] = $secretUrls[$mode];
$action = (string) ($request['action'] ?? '');
$result = match ($action) {
    'capabilities' => ['capabilities' => [
        'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
        'environment.inspect', 'environment.mutation.acquire', 'environment.mutation.read',
        'environment.mutation.release', 'environment.ttl', 'environment.ttl.read',
        'environment.url.discover', 'environment.url.set', 'operation.receipts',
        'repository.materialize', 'snapshot.set.abort', 'snapshot.set.create',
        'snapshot.set.prepare', 'snapshot.set.read', 'snapshot.set.restore',
    ]],
    'inspect', 'attach', 'create' => $identity + ['presence' => 'present'],
    'snapshot-prepare' => [
        'lease_generation' => 1,
        'lease_id' => 'snapshot-lease-0001',
        'lease_receipt_sha256' => $h('snapshot-lease'),
        'snapshot_session_id' => 'snapshot-session-0001',
        'source_identity' => 'environment-identity-0001',
    ],
    'snapshot-create' => [
        'database_sha256' => $h('database'),
        'lease_generation' => 1,
        'lease_id' => 'snapshot-lease-0001',
        'lease_receipt_sha256' => $h('snapshot-lease'),
        'media_sha256' => $h('media'),
        'retention_receipt_sha256' => $h('retention'),
        'semantic_snapshot_sha256' => $h('semantic'),
        'snapshot_session_id' => 'snapshot-session-0001',
        'snapshot_set_id' => 'snapshot-set-0001',
        'snapshot_set_receipt_sha256' => $h('snapshot-receipt'),
        'source_identity' => 'environment-identity-0001',
    ],
    'snapshot-read' => [
        'database_sha256' => $h('database'),
        'immutable' => true,
        'lease_generation' => 1,
        'lease_id' => 'snapshot-lease-0001',
        'lease_receipt_sha256' => $h('snapshot-lease'),
        'media_sha256' => $h('media'),
        'retention_receipt_sha256' => $h('retention'),
        'semantic_snapshot_sha256' => $h('semantic'),
        'snapshot_session_id' => 'snapshot-session-0001',
        'snapshot_set_id' => 'snapshot-set-0001',
        'snapshot_set_receipt_sha256' => $h('snapshot-receipt'),
        'source_identity' => 'environment-identity-0001',
    ],
    'snapshot-abort' => [
        'disposition' => 'aborted',
        'lease_generation' => 1,
        'lease_id' => 'snapshot-lease-0001',
        'lease_receipt_sha256' => $h('snapshot-lease'),
        'snapshot_session_id' => 'snapshot-session-0001',
        'source_identity' => 'environment-identity-0001',
    ],
    'mutation-acquire', 'mutation-read' => $identity + [
        'mutation_generation' => 1,
        'mutation_id' => 'mutation-lease-0001',
        'mutation_owner' => (string) ($request['operation_id'] ?? ''),
        'mutation_receipt_sha256' => $h('mutation-held'),
        'state' => 'held',
    ],
    'mutation-release' => $identity + [
        'mutation_generation' => 1,
        'mutation_id' => 'mutation-lease-0001',
        'mutation_owner' => (string) ($request['operation_id'] ?? ''),
        'mutation_receipt_sha256' => $h('mutation-released'),
        'state' => 'released',
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
        'ttl_receipt_sha256' => $h('ttl'),
        'ttl_state' => 'active',
    ],
    'ttl-read' => $identity + [
        'expires_at' => '2030-01-02T03:04:05Z',
        'ttl_generation' => 4,
        'ttl_lease_id' => 'ttl-lease-identity-0001',
        'ttl_receipt_sha256' => $h('ttl'),
        'ttl_state' => 'active',
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
    'environment' => $mode === 'wrong-environment' ? 'foreign-environment' : (string) ($request['environment'] ?? ''),
    'format' => 'duo-branch-environment-provider-response/v1',
    'operation_id' => (string) ($request['operation_id'] ?? ''),
    'provider' => [
        'id' => $mode === 'switch-provider' && $action !== 'capabilities' ? 'foreign-provider' : 'fixture-provider',
        'protocol' => 1,
    ],
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
        el_ok(is_array($decoded) && EnvironmentLifecycleCanon::encode($decoded) === $line
            && ($decoded['environment'] ?? null) === 'branch', 'provider request is canonical and environment-bound');
    }

    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('bad-cap', $config('bad-capability'))->capabilities($operation),
        'unknown capability',
        'provider capability vocabulary is closed'
    );
    el_throws(
        static function () use ($config, $operation): void {
            $mismatch = CommandEnvironmentProvider::fromEnvironment('mismatch', $config('mismatch'));
            $mismatch->capabilities($operation);
            $mismatch->perform('attach', $operation, []);
        },
        'not bound to the request',
        'response action and operation identity are request-bound'
    );
    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('wrong-environment', $config('wrong-environment'))->capabilities($operation),
        'not bound to the request',
        'response environment is request-bound'
    );
    el_throws(
        static function () use ($config, $operation): void {
            $switched = CommandEnvironmentProvider::fromEnvironment('switch-provider', $config('switch-provider'));
            $switched->capabilities($operation);
            $switched->perform('attach', $operation, []);
        },
        'identity changed',
        'provider identity cannot switch after capability negotiation'
    );
    el_throws(
        static fn() => CommandEnvironmentProvider::fromEnvironment('pretty', $config('noncanonical'))->capabilities($operation),
        'noncanonical evidence',
        'provider response must be canonical bytes'
    );
    try {
        CommandEnvironmentProvider::fromEnvironment('failed', $config('fail'))->capabilities($operation);
        el_fail('provider failure was accepted');
    } catch (Throwable $e) {
        el_ok(!str_contains($e->getMessage(), 'SUPER-SECRET'), 'provider failure output is redacted');
    }
    foreach (['credential-url', 'signed-query-url', 'fragment-secret-url'] as $mode) {
        try {
            $secretUrlProvider = CommandEnvironmentProvider::fromEnvironment($mode, $config($mode));
            $secretUrlProvider->capabilities($operation);
            $secretUrlProvider->perform('attach', $operation, []);
            el_fail("provider $mode was accepted into durable/public identity evidence");
        } catch (Throwable $e) {
            el_ok(
                str_contains($e->getMessage(), 'credential-free HTTP(S) base URL')
                    && !str_contains($e->getMessage(), 'provider-password')
                    && !str_contains($e->getMessage(), 'provider-token'),
                "provider $mode is refused before durable/public identity evidence with a redacted diagnostic"
            );
        }
    }

    $publicRoot = $tmp . '/public-secret-url';
    mkdir($publicRoot, 0700, true);
    file_put_contents($publicRoot . '/README.md', "public URL refusal fixture\n");
    el_ok(el_process(['git', 'init', '--quiet', '--initial-branch=feature-secret-url'], $publicRoot)['exit'] === 0
        && el_process(['git', 'config', 'user.name', 'DUO environment lifecycle fixture'], $publicRoot)['exit'] === 0
        && el_process(['git', 'config', 'user.email', 'duo-environment@example.invalid'], $publicRoot)['exit'] === 0
        && el_process(['git', 'add', '--', 'README.md'], $publicRoot)['exit'] === 0
        && el_process(['git', 'commit', '--quiet', '-m', 'fixture'], $publicRoot)['exit'] === 0,
        'public secret-URL fixture has a clean attached branch');
    $publicEnvs = $tmp . '/public-secret-url-envs.json';
    $publicEntry = [
        'transport' => 'local',
        'wp_path' => '/tmp',
        'repo_path' => $publicRoot,
        'environment_provider' => [
            'command' => [PHP_BINARY, $providerScript, 'signed-query-url'],
            'timeout_seconds' => 5,
        ],
    ];
    file_put_contents($publicEnvs, json_encode([
        'envs' => ['production' => $publicEntry, 'branch' => $publicEntry],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $publicRefusal = el_cli([
        '--envs-file=' . $publicEnvs,
        'env', 'materialize', 'branch', '--from', 'production',
        '--branch', 'feature-secret-url', '--format=json',
    ], $publicRoot);
    $publicOutput = $publicRefusal['stdout'] . $publicRefusal['stderr'];
    el_ok($publicRefusal['exit'] !== 0
        && str_contains($publicRefusal['stderr'], 'credential-free HTTP(S) base URL')
        && !str_contains($publicOutput, 'provider-token')
        && !str_contains($publicOutput, 'X-Amz-Signature')
        && !el_tree_contains($publicRoot . '/.git/duo-environments', 'provider-token')
        && !el_tree_contains($publicRoot . '/.git/duo-environments', 'X-Amz-Signature'),
        'public materialize refuses a signed provider URL before journal or CLI receipt disclosure');

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

    $trackedRoot = $tmp . '/tracked-registry';
    mkdir($trackedRoot, 0700, true);
    $providerLaunchLog = $trackedRoot . '/provider-launched.log';
    $trackedEntry = [
        'transport' => 'local',
        'wp_path' => '/tmp',
        'repo_path' => $trackedRoot,
        'environment_provider' => [
            'command' => [PHP_BINARY, $providerScript, 'ok', $providerLaunchLog],
            'timeout_seconds' => 5,
        ],
    ];
    file_put_contents($trackedRoot . '/.duo-envs.json', json_encode([
        'envs' => ['production' => $trackedEntry, 'branch' => $trackedEntry],
    ], JSON_THROW_ON_ERROR));
    el_ok(el_process(['git', 'init', '--quiet'], $trackedRoot)['exit'] === 0
        && el_process(['git', 'add', '--', '.duo-envs.json'], $trackedRoot)['exit'] === 0,
        'tracked-overlay fixture is indexed by Git');
    $trackedOverlay = el_cli([
        'env', 'materialize', 'branch', '--from', 'production', '--branch', 'feature/tracked-overlay',
    ], $trackedRoot);
    el_ok($trackedOverlay['exit'] !== 0
        && str_contains($trackedOverlay['stderr'], 'refusing a Git-tracked .duo-envs.json')
        && !is_file($providerLaunchLog),
        'auto-discovery cannot grant provider execution authority to a tracked overlay');
    $explicitlyTrusted = Registry::load($trackedRoot . '/.duo-envs.json', $trackedRoot);
    el_ok(($explicitlyTrusted['branch']['_machine_local'] ?? false) === true,
        'explicit --envs-file equivalent remains an operator-selected trust input');

    $nestedRegistryRoot = $tmp . '/nested-registry';
    mkdir($nestedRegistryRoot . '/code/wp-content/plugins/acme', 0700, true);
    el_ok(el_process(['git', 'init', '--quiet'], $nestedRegistryRoot)['exit'] === 0,
        'nested-registry authority fixture has an actual Git worktree root');
    file_put_contents($nestedRegistryRoot . '/site.duo.json', json_encode(['envs' => [
        'production' => ['transport' => 'ssh', 'host' => 'trusted.example', 'wp_path' => '/wp', 'repo_path' => '/repo'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($nestedRegistryRoot . '/.duo-envs.json', json_encode(['envs' => [
        'production' => ['transport' => 'ssh', 'host' => 'trusted-local.example', 'wp_path' => '/wp', 'repo_path' => '/repo'],
    ]], JSON_THROW_ON_ERROR));
    $nestedOverlay = $nestedRegistryRoot . '/code/wp-content/plugins/acme/.duo-envs.json';
    file_put_contents($nestedOverlay, json_encode(['envs' => [
        'production' => ['transport' => 'ssh', 'host' => 'attacker.invalid', 'wp_path' => '/wp', 'repo_path' => '/repo'],
    ]], JSON_THROW_ON_ERROR));
    el_throws(
        static fn() => Registry::load(null, dirname($nestedOverlay)),
        'outside registry root',
        'a nested untracked overlay cannot shadow the site-root environment authority'
    );
    $rootRegistry = Registry::load(null, $nestedRegistryRoot);
    el_ok(($rootRegistry['production']['host'] ?? null) === 'trusted-local.example',
        'the co-located machine-local overlay still replaces the checked-in environment entry');
    unlink($nestedOverlay);
    file_put_contents(dirname($nestedOverlay) . '/site.duo.json', json_encode(['envs' => [
        'production' => ['transport' => 'ssh', 'host' => 'attacker.invalid', 'wp_path' => '/wp', 'repo_path' => '/repo'],
    ]], JSON_THROW_ON_ERROR));
    el_throws(
        static fn() => Registry::load(null, dirname($nestedOverlay)),
        'refusing a nested site.duo.json',
        'vendored code cannot shadow the Git-root site registry with a nearer site.duo.json'
    );
    // DUO-3490: every other refusal in this product carries a remedy; this
    // one didn't until now. Pin the actionable clause itself (not just the
    // diagnosis) so a future edit cannot quietly drop it back to a dead end.
    el_throws(
        static fn() => Registry::load(null, dirname($nestedOverlay)),
        'make the site repo its own Git worktree root (git init inside it) or move site.duo.json '
            . 'to the enclosing worktree root and run this command from there',
        'nested site.duo.json refusal must name a concrete remedy'
    );

    $missing = el_cli(['env', 'materialize', 'branch', '--from', 'production']);
    el_ok($missing['exit'] !== 0 && str_contains($missing['stderr'], 'requires exactly --from')
        && !str_contains($missing['stderr'], 'environment_provider'), 'public parser refuses incomplete intent before registry/provider access');
    $unknown = el_cli(['env', 'materialize', 'branch', '--from', 'production', '--branch', 'feature', '--receipt=forged']);
    el_ok($unknown['exit'] !== 0 && str_contains($unknown['stderr'], "unsupported flag '--receipt=forged'")
        && !str_contains($unknown['stderr'], 'environment_provider'), 'documented spaced flags parse before caller-owned receipt fields are refused');
    $equalsCompatibility = el_cli(['env', 'materialize', 'branch', '--from=production', '--branch=feature', '--receipt=forged']);
    el_ok($equalsCompatibility['exit'] !== 0 && str_contains($equalsCompatibility['stderr'], "unsupported flag '--receipt=forged'")
        && !str_contains($equalsCompatibility['stderr'], 'environment_provider'), 'equals-form materialize flags remain backward compatible without widening privileged inputs');
    $reapFlags = el_cli(['env', 'reap', 'branch', '--force']);
    el_ok($reapFlags['exit'] !== 0 && str_contains($reapFlags['stderr'], 'accepts only optional --format=json'), 'public reap has no force or name-only destruction escape hatch');
    $help = el_cli(['--help']);
    el_ok($help['exit'] === 0 && str_contains($help['stdout'], 'duo env materialize') && str_contains($help['stdout'], 'duo env reap'), 'public help documents materialize and exact reap');
    el_ok(
        str_contains($help['stdout'], 'auto-discovered .duo-envs.json must sit beside it')
            && str_contains($help['stdout'], 'nested registry/overlay is refused')
            && !str_contains($help['stdout'], 'Both registry files are found by walking upward'),
        'public help matches the root-bound automatic registry discovery contract'
    );

    echo "PASS: environment lifecycle provider and journal regression\n";
} finally {
    el_remove($tmp);
}
