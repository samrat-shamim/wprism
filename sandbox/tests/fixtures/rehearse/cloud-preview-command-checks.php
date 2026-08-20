<?php
/**
 * Red product-path regression for a dynamically allocated Duo cloud preview.
 *
 * EnvironmentCommand must acquire the provider-owned resource and its held
 * mutation fence before a cloud driver may send any target command. Every raw
 * or WP request is then signed and bound to that exact tenant/site/resource,
 * lease generation, ownership receipt, operation, and mutation fence. The
 * loopback endpoint rejects an unbound or stale command before execution.
 *
 * usage: php cloud-preview-command-checks.php <scratch-dir>
 */
declare(strict_types=1);

namespace Duo\Orchestrator {
    final class Refresh {
        /** @return array<string,mixed> */
        public static function rebase(
            EnvironmentDriver $driver,
            string $production,
            string $branch,
            array $resolution = []
        ): array {
            $root = trim((string) shell_exec('git rev-parse --show-toplevel'));
            $head = trim((string) shell_exec('git rev-parse HEAD'));
            exec(
                'git update-ref ' . escapeshellarg('refs/heads/' . $branch) . ' ' . escapeshellarg($head),
                $output,
                $exit
            );
            if ($exit !== 0) {
                throw new \RuntimeException('cloud fixture could not create the candidate ref');
            }
            $path = $root . '/.git/cloud-preview-plan-' . hash('sha256', $branch) . '.json';
            file_put_contents($path, json_encode([
                'context' => ['production_snapshot_hash' => hash('sha256', 'semantic-production')],
                'format' => 'duo-refresh-plan/v1',
                'plan_hash' => hash('sha256', 'cloud-semantic-plan-' . $branch),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return [
                'head' => $head,
                'new_branch' => $branch,
                'plan_path' => $path,
                'run_id' => 'cloud-preview-fixture',
            ];
        }
    }

    final class CodeDeploy {
        /** @return array<string,mixed> */
        public static function compile(EnvironmentDriver $driver, string $repo, string $artifact): array {
            return [
                'exit' => 0,
                'stdout' => '',
                'stderr' => '',
                'summary' => [
                    'artifact_hash' => hash('sha256', 'cloud-outer-release'),
                    'code' => ['code_revision' => hash('sha256', 'cloud-code-release')],
                    'revision_hash' => hash('sha256', 'cloud-state-release'),
                ],
            ];
        }
    }

    final class PlanSummary {
        /** @return array<string,mixed> */
        public static function render(array $plan): array {
            return [
                'lines' => [],
                'ok' => ($plan['drift'] ?? []) === [] && ($plan['conflict'] ?? []) === [],
            ];
        }
    }
}

namespace {

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/EnvironmentDriver.php';
require_once dirname(__DIR__, 4) . '/cli/src/Plan/PlanContract.php';
require_once dirname(__DIR__, 4) . '/cli/src/Command/EnvironmentCommand.php';

use Duo\Orchestrator\EnvironmentCommand;
use Duo\Orchestrator\EnvironmentLifecycleCanon;
use Duo\Orchestrator\EnvironmentTransportFactory;
use Duo\Orchestrator\CloudOriginExportClient;
use Duo\Orchestrator\CloudPreviewTransport;
use Duo\Orchestrator\Registry;

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: cloud-preview-command-checks.php <scratch-dir>\n");
    exit(2);
}
putenv('DUO_TEST_MODE=1');

/** @param list<string> $command */
function cpc_run(array $command, ?string $cwd = null): string {
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start cloud-preview fixture process');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('cloud-preview fixture command failed: ' . implode(' ', $command) . " :: $stderr");
    }
    return trim($stdout);
}

/** One complete plan envelope, as the target agent emits it. */
function cpc_plan(): array {
    return [
        'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
        'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
        'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
        'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
        'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
        'skipped_user_meta' => [], 'unchanged' => [], 'update' => [], 'uploads_inventory' => [],
        'warnings' => [],
    ];
}

function cpc_write(string $path, string $bytes, int $mode = 0600): void {
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException("could not write cloud-preview fixture '$path'");
    }
    chmod($path, $mode);
}

/** @return list<array<string,mixed>> */
function cpc_events(string $path): array {
    $events = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event) || array_is_list($event)) {
            throw new RuntimeException('cloud-preview timeline contains a non-object row');
        }
        $events[] = $event;
    }
    return $events;
}

function cpc_executed_controls(string $path): int {
    $executed = 0;
    foreach (cpc_events($path) as $event) {
        if (($event['kind'] ?? null) === 'control' && ($event['executed'] ?? null) === true) {
            $executed++;
        }
    }
    return $executed;
}

function cpc_control_count(string $path): int {
    $controls = 0;
    foreach (cpc_events($path) as $event) {
        if (($event['kind'] ?? null) === 'control') {
            $controls++;
        }
    }
    return $controls;
}

/** @return array{body:string,status:int} */
function cpc_http_post(string $endpoint, string $body): array {
    $context = stream_context_create(['http' => [
        'content' => $body,
        'follow_location' => 0,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
        'ignore_errors' => true,
        'method' => 'POST',
        'timeout' => 4,
    ]]);
    $stream = @fopen($endpoint, 'rb', false, $context);
    if (!is_resource($stream)) {
        throw new RuntimeException('could not contact the loopback cloud control fixture');
    }
    $metadata = stream_get_meta_data($stream);
    $headers = is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [];
    $status = 0;
    foreach ($headers as $header) {
        if (is_string($header) && preg_match('#^HTTP/\S+\s+([0-9]{3})(?:\s|$)#D', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    $response = (string) stream_get_contents($stream);
    fclose($stream);
    return ['body' => $response, 'status' => $status];
}

/** @param array<string,mixed> $payload */
function cpc_signed_request(array $payload, string $keyId, string $secret): string {
    return EnvironmentLifecycleCanon::encode([
        'format' => 'duo-cloud-preview-signed-envelope/v1',
        'key_id' => $keyId,
        'payload' => $payload,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            EnvironmentLifecycleCanon::encode($payload),
            $secret
        )),
    ]) . "\n";
}

/** @param array<string,mixed> $payload */
function cpc_request_id(array $payload): string {
    return hash(
        'sha256',
        "duo-cloud-preview-command/v1\0" . ($payload['tenant_id'] ?? '')
            . "\0" . ($payload['site_id'] ?? '')
            . "\0" . ($payload['operation_id'] ?? '')
            . "\0" . ($payload['command_phase'] ?? '')
            . "\0" . ($payload['command_index'] ?? '')
    );
}

function cpc_tree_contains(string $root, string $needle): bool {
    if (!is_dir($root)) {
        return false;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        $bytes = file_get_contents($item->getPathname());
        if (is_string($bytes) && str_contains($bytes, $needle)) {
            return true;
        }
    }
    return false;
}

/** @param resource|null $process */
function cpc_stop_endpoint(mixed &$process, string $stopPath): void {
    if (!is_resource($process)) {
        return;
    }
    @touch($stopPath);
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            proc_close($process);
            $process = null;
            return;
        }
        usleep(100000);
    }
    @proc_terminate($process, 9);
    @proc_close($process);
    $process = null;
}

$root = dirname(__DIR__, 4);
$site = $scratch . '/site';
$wpPath = $scratch . '/wp';
$bin = $scratch . '/bin';
$keyDir = $scratch . '/keys';
$timeline = $scratch . '/timeline.ndjson';
$provider = $scratch . '/provider.php';
$endpointReady = $scratch . '/endpoint.ready';
$endpointStop = $scratch . '/endpoint.stop';
$endpointStdout = $scratch . '/endpoint.stdout';
$endpointStderr = $scratch . '/endpoint.stderr';
$expectedPath = $scratch . '/control-expected.json';
$fenceStatePath = $scratch . '/fence-state';
$responseModePath = $scratch . '/response-mode';
$clientPublicPath = $keyDir . '/site-public.key';
$clientSecretPath = $keyDir . '/site-secret.key';
$serverPublicPath = $keyDir . '/control-public.key';
$serverSecretPath = $keyDir . '/control-secret.key';
foreach ([$site, $wpPath, $bin, $keyDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException("could not create cloud-preview fixture directory '$directory'");
    }
}

$wpFixture = <<<'SH'
#!/bin/sh
set -eu
if [ "$#" -ne 3 ] || [ "$2" != 'eval' ]; then
    exit 64
fi
case "$3" in
    *'get_option("home")'*'wp_upload_dir(null, false)'*) ;;
    *) exit 65 ;;
esac
printf '%s\n%s\n' 'https://production-fixture.example.test' 'https://production-fixture.example.test/wp-content/uploads'
SH;
cpc_write($bin . '/wp', $wpFixture . "\n");
if (!chmod($bin . '/wp', 0700)) {
    throw new RuntimeException('could not make the cloud-preview WP fixture executable');
}
putenv('PATH=' . $bin . PATH_SEPARATOR . (string) getenv('PATH'));

cpc_run(['git', 'init', '-b', 'feature/cloud-preview'], $site);
cpc_run(['git', 'config', 'user.email', 'cloud-preview@example.invalid'], $site);
cpc_run(['git', 'config', 'user.name', 'Cloud Preview Fixture'], $site);
cpc_write($site . '/tracked.txt', "cloud preview\n");
cpc_run(['git', 'add', 'tracked.txt'], $site);
cpc_run(['git', 'commit', '-m', 'cloud preview fixture'], $site);

$clientPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'duo-cloud-preview-client-fixture', true));
$clientSecret = sodium_crypto_sign_secretkey($clientPair);
$clientPublic = sodium_crypto_sign_publickey($clientPair);
$serverPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'duo-cloud-preview-server-fixture', true));
$serverSecret = sodium_crypto_sign_secretkey($serverPair);
$serverPublic = sodium_crypto_sign_publickey($serverPair);
$clientSecretEncoded = base64_encode($clientSecret);
cpc_write($clientSecretPath, $clientSecretEncoded . "\n");
cpc_write($clientPublicPath, base64_encode($clientPublic) . "\n");
cpc_write($serverSecretPath, base64_encode($serverSecret) . "\n");
cpc_write($serverPublicPath, base64_encode($serverPublic) . "\n");
sodium_memzero($clientSecret);
sodium_memzero($serverSecret);

$identity = [
    'environment_identity' => 'cloud-environment-identity-0001',
    'lease_generation' => 1,
    'lease_id' => 'cloud-lease-identity-0001',
    'ownership_receipt_sha256' => hash('sha256', 'cloud-owner-generation-1'),
    'resource_id' => 'cloud-preview-slot-0001',
    'url' => 'https://cloud-preview-fixture.example.test',
];
$mutation = [
    'mutation_generation' => 1,
    'mutation_id' => 'cloud-mutation-lease-0001',
    'mutation_receipt_sha256' => hash('sha256', 'cloud-mutation-held-generation-1'),
];
$expectations = [
    'environment' => 'preview',
    'plan' => cpc_plan(),
    'request_key_id' => 'site-key-fixture-0001',
    'response_key_id' => 'control-key-fixture-0001',
    'site_id' => 'site-fixture-0001',
    'target' => [
        'environment_identity' => $identity['environment_identity'],
        'lease_generation' => $identity['lease_generation'],
        'lease_id' => $identity['lease_id'],
        'mutation_generation' => $mutation['mutation_generation'],
        'mutation_id' => $mutation['mutation_id'],
        'mutation_receipt_sha256' => $mutation['mutation_receipt_sha256'],
        'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'resource_id' => $identity['resource_id'],
    ],
    'tenant_id' => 'tenant-fixture-0001',
];
cpc_write($expectedPath, EnvironmentLifecycleCanon::encode($expectations) . "\n");
cpc_write($fenceStatePath, EnvironmentLifecycleCanon::encode(['state' => 'absent']) . "\n");
cpc_write($responseModePath, "\n");

$endpointProcess = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/cloud-preview-control-endpoint.php',
        $endpointReady,
        $endpointStop,
        $timeline,
        $expectedPath,
        $clientPublicPath,
        $serverSecretPath,
        $fenceStatePath,
        $responseModePath,
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $endpointStdout, 'a'],
        2 => ['file', $endpointStderr, 'a'],
    ],
    $endpointPipes,
    $scratch,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($endpointProcess)) {
    throw new RuntimeException('could not start the loopback cloud control endpoint');
}
fclose($endpointPipes[0]);
register_shutdown_function(static function () use (&$endpointProcess, $endpointStop): void {
    cpc_stop_endpoint($endpointProcess, $endpointStop);
});
for ($attempt = 0; $attempt < 150 && !is_file($endpointReady); $attempt++) {
    usleep(20000);
}
$endpoint = trim((string) @file_get_contents($endpointReady));
if (preg_match('#^http://127\.0\.0\.1:[0-9]+/v1/preview/control$#D', $endpoint) !== 1) {
    $detail = trim((string) @file_get_contents($endpointStderr));
    throw new RuntimeException('loopback cloud control endpoint did not become ready: ' . $detail);
}

$providerSource = <<<'PHP'
<?php
declare(strict_types=1);
$role = $argv[1] ?? '';
$timeline = $argv[2] ?? '';
$fenceState = $argv[3] ?? '';
$raw = (string) stream_get_contents(STDIN);
$request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
function fixture_canon(mixed $value): string {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = json_decode(fixture_canon($child), true);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
$action = (string) ($request['action'] ?? '');
$input = is_array($request['input'] ?? null) ? $request['input'] : [];
$event = ['action' => $action, 'kind' => 'provider', 'operation_id' => (string) ($request['operation_id'] ?? ''), 'role' => $role];
file_put_contents($timeline, fixture_canon($event) . "\n", FILE_APPEND | LOCK_EX);
$hash = static fn(string $value): string => hash('sha256', $value);
$target = [
    'environment_identity' => 'cloud-environment-identity-0001',
    'lease_generation' => 1,
    'lease_id' => 'cloud-lease-identity-0001',
    'ownership_receipt_sha256' => $hash('cloud-owner-generation-1'),
    'resource_id' => 'cloud-preview-slot-0001',
    'url' => 'https://cloud-preview-fixture.example.test',
];
$source = [
    'environment_identity' => 'cloud-source-identity-0001',
    'lease_generation' => 1,
    'lease_id' => 'cloud-source-lease-0001',
    'ownership_receipt_sha256' => $hash('cloud-source-owner'),
    'resource_id' => 'cloud-source-resource-0001',
    'url' => 'https://source-fixture.example.test',
];
$identity = $role === 'source' ? $source : $target;
$owner = (string) ($input['mutation_owner'] ?? $input['expected_mutation_owner'] ?? '');
$heldMutation = $target + [
    'mutation_generation' => 1,
    'mutation_id' => 'cloud-mutation-lease-0001',
    'mutation_owner' => $owner,
    'mutation_receipt_sha256' => $hash('cloud-mutation-held-generation-1'),
    'state' => 'held',
];
$releasedMutation = $heldMutation;
$releasedMutation['mutation_receipt_sha256'] = $hash('cloud-mutation-released-generation-1');
$releasedMutation['state'] = 'released';
$capabilities = [
    'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
    'environment.inspect', 'environment.mutation.acquire', 'environment.mutation.read',
    'environment.mutation.release', 'environment.ttl', 'environment.ttl.read',
    'environment.url.discover', 'environment.url.set', 'operation.receipts',
    'repository.materialize', 'snapshot.set.abort', 'snapshot.set.create',
    'snapshot.set.prepare', 'snapshot.set.read', 'snapshot.set.restore',
];
$result = match ($action) {
    'capabilities' => ['capabilities' => $capabilities],
    'inspect', 'attach', 'create' => $identity + ['presence' => 'present'],
    'snapshot-prepare' => [
        'lease_generation' => 1,
        'lease_id' => 'cloud-snapshot-lease-0001',
        'lease_receipt_sha256' => $hash('cloud-snapshot-lease'),
        'snapshot_session_id' => (string) ($input['snapshot_session_id'] ?? ''),
        'source_identity' => $source['environment_identity'],
    ],
    'snapshot-create' => [
        'database_sha256' => $hash('cloud-database'),
        'lease_generation' => 1,
        'lease_id' => 'cloud-snapshot-lease-0001',
        'lease_receipt_sha256' => $hash('cloud-snapshot-lease'),
        'media_sha256' => $hash('cloud-media'),
        'retention_receipt_sha256' => $hash('cloud-retention'),
        'semantic_snapshot_sha256' => (string) ($input['expected_semantic_snapshot_sha256'] ?? ''),
        'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
        'snapshot_set_id' => 'cloud-snapshot-set-0001',
        'snapshot_set_receipt_sha256' => $hash('cloud-snapshot-set'),
        'source_identity' => $source['environment_identity'],
    ],
    'snapshot-read' => [
        'database_sha256' => $hash('cloud-database'),
        'immutable' => true,
        'lease_generation' => 1,
        'lease_id' => 'cloud-snapshot-lease-0001',
        'lease_receipt_sha256' => $hash('cloud-snapshot-lease'),
        'media_sha256' => $hash('cloud-media'),
        'retention_receipt_sha256' => $hash('cloud-retention'),
        'semantic_snapshot_sha256' => $hash('semantic-production'),
        'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
        'snapshot_set_id' => (string) ($input['expected_snapshot_set_id'] ?? ''),
        'snapshot_set_receipt_sha256' => (string) ($input['expected_snapshot_set_receipt_sha256'] ?? ''),
        'source_identity' => $source['environment_identity'],
    ],
    'snapshot-abort' => [
        'disposition' => 'aborted',
        'lease_generation' => 1,
        'lease_id' => 'cloud-snapshot-lease-0001',
        'lease_receipt_sha256' => $hash('cloud-snapshot-lease'),
        'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
        'source_identity' => $source['environment_identity'],
    ],
    'mutation-acquire', 'mutation-read' => $heldMutation,
    'mutation-release' => $releasedMutation,
    'snapshot-restore' => $target + ['snapshot_set_id' => (string) ($input['snapshot_set_id'] ?? '')],
    'repository-materialize' => $target + [
        'branch_commit' => (string) ($input['branch_commit'] ?? ''),
        'repository_receipt_sha256' => $hash('cloud-repository'),
    ],
    'url-set' => $target,
    'ttl-set', 'ttl-read' => $target + [
        'expires_at' => '2030-01-02T03:04:05Z',
        'ttl_generation' => 1,
        'ttl_lease_id' => 'cloud-ttl-lease-0001',
        'ttl_receipt_sha256' => $hash('cloud-ttl'),
        'ttl_state' => 'active',
    ],
    'destroy', 'detach' => [
        'absence_proof_sha256' => $hash('cloud-absence'),
        'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
        'environment_identity' => $target['environment_identity'],
        'lease_generation' => $target['lease_generation'],
        'lease_id' => $target['lease_id'],
        'ownership_receipt_sha256' => $target['ownership_receipt_sha256'],
        'resource_id' => $target['resource_id'],
    ],
    default => [],
};
$response = [
    'action' => $action,
    'environment' => (string) ($request['environment'] ?? ''),
    'format' => 'duo-branch-environment-provider-response/v1',
    'operation_id' => (string) ($request['operation_id'] ?? ''),
    'provider' => ['id' => 'cloud-fixture-' . $role, 'protocol' => 1],
    'result' => $result,
    'status' => 'ok',
];
if ($role === 'target' && in_array($action, ['mutation-acquire', 'mutation-release'], true)) {
    $state = [
        'operation_id' => (string) ($request['operation_id'] ?? ''),
        'state' => $action === 'mutation-acquire' ? 'held' : 'released',
        'target' => [
            'environment_identity' => $target['environment_identity'],
            'lease_generation' => $target['lease_generation'],
            'lease_id' => $target['lease_id'],
            'mutation_generation' => $heldMutation['mutation_generation'],
            'mutation_id' => $heldMutation['mutation_id'],
            'mutation_owner' => $heldMutation['mutation_owner'],
            'mutation_receipt_sha256' => $heldMutation['mutation_receipt_sha256'],
            'ownership_receipt_sha256' => $target['ownership_receipt_sha256'],
            'resource_id' => $target['resource_id'],
        ],
    ];
    file_put_contents($fenceState, fixture_canon($state) . "\n", LOCK_EX);
}
echo fixture_canon($response), "\n";
PHP;
cpc_write($provider, $providerSource . "\n", 0700);

$providerCommand = static fn(string $role): array => [PHP_BINARY, $provider, $role, $timeline, $fenceStatePath];
$envsPath = $scratch . '/envs.json';
$cloudConfig = [
    'control_endpoint' => $endpoint,
    'lifecycle_endpoint' => str_replace('/control', '/lifecycle', $endpoint),
    'request_key_id' => $expectations['request_key_id'],
    'request_signing_key' => $clientSecretPath,
    'response_key_id' => $expectations['response_key_id'],
    'response_public_key' => $serverPublicPath,
    'site_id' => $expectations['site_id'],
    'tenant_id' => $expectations['tenant_id'],
    'timeout_seconds' => 1,
];
cpc_write($envsPath, json_encode(['envs' => [
    'production' => [
        'transport' => 'local',
        'wp_path' => $wpPath,
        'repo_path' => $site,
        'environment_provider' => [
            'command' => $providerCommand('source'),
            'timeout_seconds' => 5,
        ],
    ],
    'preview' => [
        // The endpoint and key references are stable. The target resource is
        // deliberately absent from config and exists only after provider
        // create returns its generation-bound identity.
        'transport' => 'cloud-preview',
        'repo_path' => '/srv/duo/site-repo',
        'cloud_preview' => $cloudConfig,
    ],
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$loadedEnvironments = Registry::load($envsPath, $site);
$previewConfiguration = Registry::get($loadedEnvironments, 'preview');
$evidenceDriver = EnvironmentTransportFactory::make('preview', $previewConfiguration);
if (!$evidenceDriver instanceof CloudPreviewTransport) {
    throw new RuntimeException('fixture preview did not construct the cloud-preview transport');
}
$reviewedContainment = $evidenceDriver->reviewedBaseContainment(
    '20260818-120000-' . str_repeat('c', 24)
);
$reviewedContainmentReplay = $evidenceDriver->reviewedBaseContainment(
    '20260818-120000-' . str_repeat('c', 24)
);
duo_check(
    ($reviewedContainment['format'] ?? null) === 'duo-reviewed-preview-base-containment/v1'
        && ($reviewedContainment['egress_evidence'] ?? null)
            === 'host-nft-input-forward-default-deny-readback/v1'
        && ($reviewedContainment['routing_evidence'] ?? null)
            === 'credential-free-route-authority-readback/v1'
        && ($reviewedContainment['secrets_evidence'] ?? null)
            === 'generation-private-files-readonly-mount-readback/v1'
        && ($reviewedContainment['storage_evidence'] ?? null)
            === 'dm-crypt-xfs-project-quota-exact-readback/v1'
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($reviewedContainment['seccomp_profile_sha256'] ?? '')) === 1
        && is_array($reviewedContainment['reviewed_base'] ?? null)
        && $reviewedContainmentReplay === $reviewedContainment,
    'the controller deterministically replays signed reviewed-base containment before any preview create'
);
duo_check(
    $evidenceDriver->originExportClient() instanceof CloudOriginExportClient,
    'the cloud transport derives its origin export client from the same paired service authority'
);

cpc_write($responseModePath, "bad-containment-hash\n");
$badContainmentRefused = false;
try {
    $badEvidenceDriver = EnvironmentTransportFactory::make('preview', $previewConfiguration);
    if (!$badEvidenceDriver instanceof CloudPreviewTransport) {
        throw new RuntimeException('fixture preview did not reconstruct the cloud-preview transport');
    }
    $badEvidenceDriver->reviewedBaseContainment('20260818-120000-' . str_repeat('d', 24));
} catch (RuntimeException $error) {
    $badContainmentRefused = str_contains($error->getMessage(), 'containment hash does not verify');
} finally {
    cpc_write($responseModePath, "\n");
}
duo_check(
    $badContainmentRefused,
    'a signed but unverifiable reviewed-base containment descriptor is refused before create'
);

$promotions = 0;
$promote = static function (
    \Duo\Orchestrator\EnvironmentDriver $driver,
    array $frozenContext
) use (&$promotions): array {
    $promotions++;
    $summary = $frozenContext['compiled_summary'];
    $receipt = [
        'artifact_hash' => (string) $summary['artifact_hash'],
        'checkpoint_identity' => hash('sha256', 'cloud-checkpoint-' . $frozenContext['operation_id']),
        'code_revision' => (string) $summary['code']['code_revision'],
        'format' => 'duo-branch-environment-promotion-receipt/v1',
        'operation_id' => $frozenContext['operation_id'],
        'owner' => $frozenContext['promotion_owner'],
        'state_revision' => (string) $summary['revision_hash'],
        'status' => 'completed',
    ];
    $receipt['receipt_sha256'] = hash('sha256', EnvironmentLifecycleCanon::encode($receipt));
    return $receipt;
};

$responseProbeResults = [];
$postReleaseDriver = null;
$observeSecurity = static function (
    \Duo\Orchestrator\EnvironmentDriver $boundDriver,
    ?array $replayed
) use (
    &$postReleaseDriver,
    &$responseProbeResults,
    $clientSecretPath,
    $endpoint,
    $expectations,
    $responseModePath,
    $timeline
): array {
    $postReleaseDriver = $boundDriver;
    if ($replayed !== null) {
        $responseProbeResults['replayed'] = true;
        return $replayed;
    }
    $productEvents = cpc_events($timeline);
    $responseProbeResults['product_events'] = $productEvents;
    $basePayload = null;
    foreach ($productEvents as $event) {
        if (($event['kind'] ?? null) === 'control'
            && ($event['executed'] ?? null) === true
            && is_array($event['payload'] ?? null)) {
            $basePayload = $event['payload'];
        }
    }
    if (!is_array($basePayload) || array_is_list($basePayload)) {
        throw new RuntimeException('the held-fence callback received no executed control request');
    }
    $responseProbeResults['base_payload'] = $basePayload;
    $executedBefore = cpc_executed_controls($timeline);

    $probeSecret = base64_decode(trim((string) file_get_contents($clientSecretPath)), true);
    if (!is_string($probeSecret) || strlen($probeSecret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('could not reload the fixture request key for adversarial probes');
    }
    $signed = json_decode(
        cpc_signed_request($basePayload, $expectations['request_key_id'], $probeSecret),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (($basePayload['action'] ?? null) === 'raw') {
        $signed['payload']['input']['script'] = 'printf tampered-after-signing';
    } else {
        $signed['payload']['input']['argv'][] = '--tampered-after-signing';
    }
    $responseProbeResults['tampered_body'] = cpc_http_post(
        $endpoint,
        EnvironmentLifecycleCanon::encode($signed) . "\n"
    )['status'] === 403;

    $beforeExactReplay = cpc_executed_controls($timeline);
    $responseProbeResults['exact_replay'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($basePayload, $expectations['request_key_id'], $probeSecret)
    )['status'] === 200;
    $responseProbeResults['exact_replay_no_execution'] = cpc_executed_controls($timeline) === $beforeExactReplay;

    $changedReplay = $basePayload;
    if (($changedReplay['action'] ?? null) === 'raw') {
        $changedReplay['input']['script'] = 'printf changed-valid-replay';
    } else {
        $changedReplay['input']['argv'][] = '--changed-valid-replay';
    }
    $responseProbeResults['changed_replay'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($changedReplay, $expectations['request_key_id'], $probeSecret)
    )['status'] === 403;

    $foreignTenant = $basePayload;
    $foreignTenant['tenant_id'] = 'tenant-foreign-0001';
    $foreignTenant['request_id'] = cpc_request_id($foreignTenant);
    $responseProbeResults['foreign_tenant'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($foreignTenant, $expectations['request_key_id'], $probeSecret)
    )['status'] === 403;

    $foreignSite = $basePayload;
    $foreignSite['site_id'] = 'site-foreign-0001';
    $foreignSite['request_id'] = cpc_request_id($foreignSite);
    $responseProbeResults['foreign_site'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($foreignSite, $expectations['request_key_id'], $probeSecret)
    )['status'] === 403;

    $foreignOperation = $basePayload;
    $foreignOperation['operation_id'] = '20000101-000000-' . str_repeat('a', 24);
    $foreignOperation['target']['mutation_owner'] = 'duo-env-materialize-' . $foreignOperation['operation_id'];
    $foreignOperation['request_id'] = cpc_request_id($foreignOperation);
    $responseProbeResults['foreign_operation'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($foreignOperation, $expectations['request_key_id'], $probeSecret)
    )['status'] === 403;

    $staleFence = $basePayload;
    $staleFence['command_index'] = 1001;
    $staleFence['target']['mutation_receipt_sha256'] = hash('sha256', 'stale-cloud-fence');
    $staleFence['request_id'] = cpc_request_id($staleFence);
    $responseProbeResults['stale_fence'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($staleFence, $expectations['request_key_id'], $probeSecret)
    )['status'] === 403;

    $missingFence = $basePayload;
    $missingFence['command_index'] = 1002;
    unset($missingFence['target']['mutation_id']);
    $missingFence['request_id'] = cpc_request_id($missingFence);
    $responseProbeResults['missing_fence'] = cpc_http_post(
        $endpoint,
        cpc_signed_request($missingFence, $expectations['request_key_id'], $probeSecret)
    )['status'] === 403;
    sodium_memzero($probeSecret);

    cpc_write($responseModePath, "wrong-key\n");
    try {
        $boundDriver->captureRaw('printf wrong-response-key');
        $responseProbeResults['wrong_key'] = false;
    } catch (RuntimeException $error) {
        $responseProbeResults['wrong_key'] = str_contains(
            $error->getMessage(),
            'not signed by the paired service key'
        );
    } finally {
        cpc_write($responseModePath, "\n");
    }

    cpc_write($responseModePath, "wrong-signature\n");
    try {
        $boundDriver->captureRaw('printf wrong-response-signature');
        $responseProbeResults['wrong_signature'] = false;
    } catch (RuntimeException $error) {
        $responseProbeResults['wrong_signature'] = str_contains(
            $error->getMessage(),
            'response signature is invalid'
        );
    } finally {
        cpc_write($responseModePath, "\n");
    }

    foreach ([
        'wrong_response_action' => 'wrong-action',
        'wrong_response_environment' => 'wrong-environment',
        'wrong_response_operation' => 'wrong-operation',
        'wrong_response_request_hash' => 'wrong-request-hash',
        'wrong_response_site' => 'wrong-site',
        'wrong_response_target' => 'wrong-target',
        'wrong_response_tenant' => 'wrong-tenant',
    ] as $resultKey => $responseMode) {
        cpc_write($responseModePath, $responseMode . "\n");
        try {
            $boundDriver->captureRaw('printf ' . $responseMode);
            $responseProbeResults[$resultKey] = false;
        } catch (RuntimeException $error) {
            $responseProbeResults[$resultKey] = str_contains(
                $error->getMessage(),
                'response is not bound to its signed request'
            );
        } finally {
            cpc_write($responseModePath, "\n");
        }
    }

    cpc_write($responseModePath, "stall-response\n");
    $started = microtime(true);
    try {
        $boundDriver->captureRaw('printf stalled-response');
        $responseProbeResults['stalled'] = false;
    } catch (RuntimeException $error) {
        $responseProbeResults['stalled'] = true;
    } finally {
        cpc_write($responseModePath, "\n");
    }
    $responseProbeResults['stalled_elapsed'] = microtime(true) - $started;
    // The fixture server is intentionally single-threaded. Its stalled
    // response continues after the client deadline, so wait for that closed
    // test seam before the product immediately sends mutation-release.
    usleep(1700000);
    $responseProbeResults['executed_unchanged'] = cpc_executed_controls($timeline) === $executedBefore;

    return [
        'format' => 'duo-cloud-preview-security-observation/v1',
        'status' => 'ok',
    ];
};

$previous = getcwd();
chdir($site);
ob_start();
$status = EnvironmentCommand::run([
    'materialize', 'preview', '--from', 'production',
    '--branch', 'feature/cloud-preview', '--create', '--format=json',
], $envsPath, $promote, $observeSecurity);
$output = (string) ob_get_clean();
chdir($previous ?: '/');

duo_check_same(
    0,
    $status,
    'EnvironmentCommand materializes a dynamically allocated cloud-preview target through the signed control path'
);

if ($status === 0) {
    $receipt = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    duo_check(
        is_array($receipt)
            && ($receipt['mode'] ?? null) === 'create'
            && ($receipt['resource_id'] ?? null) === $identity['resource_id']
            && ($receipt['lease_generation'] ?? null) === $identity['lease_generation']
            && ($receipt['lease_id'] ?? null) === $identity['lease_id']
            && ($receipt['ownership_receipt_sha256'] ?? null) === $identity['ownership_receipt_sha256'],
        'the public receipt is bound to the provider-created resource generation and ownership lease'
    );

    $events = $responseProbeResults['product_events'] ?? null;
    if (!is_array($events) || $events === []) {
        throw new RuntimeException('the held-fence observation did not retain the product control timeline');
    }
    $createIndex = null;
    $fenceIndex = null;
    $controlIndexes = [];
    $controlPayloads = [];
    $allControlAccepted = true;
    foreach ($events as $index => $event) {
        if (($event['kind'] ?? null) === 'provider' && ($event['role'] ?? null) === 'target') {
            if (($event['action'] ?? null) === 'create' && $createIndex === null) {
                $createIndex = $index;
            }
            if (($event['action'] ?? null) === 'mutation-acquire' && $fenceIndex === null) {
                $fenceIndex = $index;
            }
        }
        if (($event['kind'] ?? null) === 'control') {
            $controlIndexes[] = $index;
            if (($event['accepted'] ?? null) !== true) {
                $allControlAccepted = false;
            } else {
                $controlPayloads[] = $event['payload'] ?? null;
            }
        }
    }
    $firstControl = $controlIndexes[0] ?? null;
    duo_check(
        is_int($createIndex) && is_int($fenceIndex) && is_int($firstControl)
            && $createIndex < $firstControl && $fenceIndex < $firstControl,
        'no target command reaches the cloud control endpoint before provider create and held-fence acquisition'
    );
    duo_check(
        $allControlAccepted && count($controlPayloads) === count($controlIndexes),
        'the signed endpoint accepts every target command; no unbound command is emitted and ignored'
    );
    duo_check(count($controlPayloads) >= 2, 'the product path reaches both raw and WP target control after binding');

    $allBound = $controlPayloads !== [];
    $actions = [];
    $phaseIndexes = [];
    foreach ($controlPayloads as $payload) {
        if (!is_array($payload) || array_is_list($payload)) {
            $allBound = false;
            continue;
        }
        $actions[] = $payload['action'] ?? null;
        $phase = $payload['command_phase'] ?? null;
        $commandIndex = $payload['command_index'] ?? null;
        if (is_string($phase) && is_int($commandIndex)) {
            $phaseIndexes[$phase][] = $commandIndex;
        }
        $target = $payload['target'] ?? null;
        $allBound = $allBound
            && ($payload['tenant_id'] ?? null) === $expectations['tenant_id']
            && ($payload['site_id'] ?? null) === $expectations['site_id']
            && ($payload['environment'] ?? null) === 'preview'
            && ($payload['operation_id'] ?? null) === ($receipt['operation_id'] ?? null)
            && is_string($phase)
            && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $phase) === 1
            && is_int($commandIndex)
            && $commandIndex >= 0
            && ($payload['request_id'] ?? null) === cpc_request_id($payload)
            && is_array($target)
            && ($target['environment_identity'] ?? null) === $identity['environment_identity']
            && ($target['resource_id'] ?? null) === $identity['resource_id']
            && ($target['lease_generation'] ?? null) === $identity['lease_generation']
            && ($target['lease_id'] ?? null) === $identity['lease_id']
            && ($target['ownership_receipt_sha256'] ?? null) === $identity['ownership_receipt_sha256']
            && ($target['mutation_generation'] ?? null) === $mutation['mutation_generation']
            && ($target['mutation_id'] ?? null) === $mutation['mutation_id']
            && ($target['mutation_receipt_sha256'] ?? null) === $mutation['mutation_receipt_sha256']
            && ($target['mutation_owner'] ?? null) === 'duo-env-materialize-' . ($receipt['operation_id'] ?? '');
    }
    duo_check($allBound, 'every target command carries the exact tenant/site, lease, ownership, operation, and held-fence tuple');
    $contiguousPhaseIndexes = $phaseIndexes !== [];
    foreach ($phaseIndexes as $indexes) {
        sort($indexes, SORT_NUMERIC);
        $contiguousPhaseIndexes = $contiguousPhaseIndexes
            && $indexes === range(0, count($indexes) - 1);
    }
    duo_check(
        $contiguousPhaseIndexes,
        'every product command has its deterministic phase/index request id with no phase-local gaps'
    );
    duo_check(
        in_array('raw', $actions, true) && in_array('wp', $actions, true),
        'both raw and WP control use the same signed lease-and-fence binding'
    );
    $releaseIndex = null;
    $allAcceptedBeforeRelease = true;
    foreach (cpc_events($timeline) as $index => $event) {
        if (($event['kind'] ?? null) === 'provider'
            && ($event['role'] ?? null) === 'target'
            && ($event['action'] ?? null) === 'mutation-release') {
            $releaseIndex = $index;
        }
        if (($event['kind'] ?? null) === 'control'
            && ($event['accepted'] ?? null) === true
            && is_int($releaseIndex)) {
            $allAcceptedBeforeRelease = false;
        }
    }
    duo_check(
        is_int($releaseIndex) && $allAcceptedBeforeRelease,
        'every accepted target command occurs before the provider releases its mutation fence'
    );
    duo_check_same(1, $promotions, 'the bound dynamic target enters the frozen promotion handoff exactly once');
    $controlsBeforeLocalReleaseProbe = cpc_control_count($timeline);
    $localReleaseRefused = false;
    if ($postReleaseDriver instanceof \Duo\Orchestrator\EnvironmentDriver) {
        try {
            $postReleaseDriver->captureRaw('printf post-release-local-probe');
        } catch (RuntimeException $error) {
            $localReleaseRefused = str_contains($error->getMessage(), 'not bound to a live provider lease');
        }
    }
    duo_check(
        $localReleaseRefused && cpc_control_count($timeline) === $controlsBeforeLocalReleaseProbe,
        'the same driver refuses locally after provider release and emits no HTTP request'
    );
    $executedBeforeProbes = cpc_executed_controls($timeline);

    $probeSecret = base64_decode(trim((string) file_get_contents($clientSecretPath)), true);
    if (!is_string($probeSecret) || strlen($probeSecret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('could not reload the fixture request key for adversarial probes');
    }
    $releasedFence = $responseProbeResults['base_payload'] ?? null;
    if (!is_array($releasedFence) || array_is_list($releasedFence)) {
        throw new RuntimeException('the held-fence callback retained no request for released-fence replay');
    }
    $releasedFenceResponse = cpc_http_post(
        $endpoint,
        cpc_signed_request($releasedFence, $expectations['request_key_id'], $probeSecret)
    );
    duo_check_same(
        403,
        $releasedFenceResponse['status'],
        'the endpoint refuses the formerly valid fence after the provider releases it'
    );

    duo_check_same(
        $executedBeforeProbes,
        cpc_executed_controls($timeline),
        'a post-release request executes zero target commands'
    );
    sodium_memzero($probeSecret);

    duo_check(($responseProbeResults['tampered_body'] ?? false) === true,
        'the endpoint refuses a request body changed after signing while its fence is held');
    duo_check(($responseProbeResults['exact_replay'] ?? false) === true,
        'the endpoint returns the cached signed response for an exact command-id replay');
    duo_check(($responseProbeResults['exact_replay_no_execution'] ?? false) === true,
        'the exact command-id replay performs no second target execution');
    duo_check(($responseProbeResults['changed_replay'] ?? false) === true,
        'the endpoint refuses changed bytes re-signed under an already used request id');
    duo_check(($responseProbeResults['foreign_tenant'] ?? false) === true,
        'the endpoint refuses a valid site signature for another tenant while its fence is held');
    duo_check(($responseProbeResults['foreign_site'] ?? false) === true,
        'the endpoint refuses a valid key used for another site while its fence is held');
    duo_check(($responseProbeResults['foreign_operation'] ?? false) === true,
        'the endpoint refuses a self-consistent operation and owner that did not acquire the live fence');
    duo_check(($responseProbeResults['stale_fence'] ?? false) === true,
        'the endpoint refuses a stale mutation-fence receipt while another receipt is held');
    duo_check(($responseProbeResults['missing_fence'] ?? false) === true,
        'the endpoint refuses a signed request with an incomplete held fence');

    duo_check(
        ($responseProbeResults['wrong_key'] ?? false) === true,
        'the cloud driver refuses a response naming an unpaired service key under the held fence'
    );
    duo_check(
        ($responseProbeResults['wrong_signature'] ?? false) === true,
        'the cloud driver refuses a response with a changed Ed25519 signature under the held fence'
    );
    duo_check(($responseProbeResults['wrong_response_request_hash'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another request hash');
    duo_check(($responseProbeResults['wrong_response_operation'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another operation');
    duo_check(($responseProbeResults['wrong_response_tenant'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another tenant');
    duo_check(($responseProbeResults['wrong_response_site'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another site');
    duo_check(($responseProbeResults['wrong_response_target'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another target tuple');
    duo_check(($responseProbeResults['wrong_response_action'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another action');
    duo_check(($responseProbeResults['wrong_response_environment'] ?? false) === true,
        'the cloud driver refuses a correctly signed response for another environment');
    $stalledResponseElapsed = $responseProbeResults['stalled_elapsed'] ?? null;
    duo_check(
        ($responseProbeResults['stalled'] ?? false) === true
            && is_float($stalledResponseElapsed)
            && $stalledResponseElapsed >= 0.5
            && $stalledResponseElapsed < 4.0,
        'a partial control response fails at the configured timeout instead of hanging'
    );
    duo_check(
        ($responseProbeResults['executed_unchanged'] ?? false) === true,
        'unpaired, invalidly signed, and stalled responses publish no simulated target execution'
    );
}

$journalRoot = $site . '/.git/duo-environments';
$publicBytes = $output
    . (string) @file_get_contents($timeline)
    . (string) @file_get_contents($endpointStdout)
    . (string) @file_get_contents($endpointStderr);
duo_check(
    !str_contains($publicBytes, $clientSecretEncoded)
        && !cpc_tree_contains($journalRoot, $clientSecretEncoded),
    'the site signing secret never enters public output, provider/control logs, or the durable environment journal'
);

$endpointParts = parse_url($endpoint);
$stall = is_array($endpointParts)
    ? @stream_socket_client(
        'tcp://' . ($endpointParts['host'] ?? '') . ':' . ($endpointParts['port'] ?? 0),
        $stallErrno,
        $stallError,
        1
    )
    : false;
$stallOpened = is_resource($stall);
if ($stallOpened) {
    fwrite(
        $stall,
        "POST /v1/preview/control HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Length: 32\r\n"
    );
}
$stopStarted = microtime(true);
cpc_stop_endpoint($endpointProcess, $endpointStop);
if (is_resource($stall)) {
    fclose($stall);
}
duo_check(
    $stallOpened && $endpointProcess === null && microtime(true) - $stopStarted < 5.0,
    'an incomplete HTTP client cannot hang the loopback endpoint shutdown path'
);
duo_check_summary('dynamic cloud-preview through EnvironmentCommand');
}
