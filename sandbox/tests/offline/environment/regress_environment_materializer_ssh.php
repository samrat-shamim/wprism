<?php
declare(strict_types=1);

/*
 * issue #3324: public attach/materialize proof over the real SSH transport.
 *
 * This is deliberately offline.  The target SSH command is executed by a
 * deterministic wrapper, while the machine-local provider remains a real
 * direct-argv provider process.  The temporary source checkout is clean and
 * attached, so `cli/wprism env materialize` exercises Registry, Refresh,
 * EnvironmentMaterializer, cmd_promote_frozen(), and exact reap as callers
 * see them.  No WordPress or plugin-specific behavior is implemented here.
 */

use WPrism\Canon;
use WPrism\Orchestrator\RefreshPlan;

$root = realpath(__DIR__ . '/../../../..');
if ($root === false) {
    throw new RuntimeException('repository root is unavailable');
}

function ssh_proof_fail(string $message): never {
    throw new RuntimeException("FAIL: $message");
}

function ssh_proof_ok(bool $condition, string $message): void {
    if (!$condition) ssh_proof_fail($message);
    echo "ok: $message\n";
}

function ssh_proof_write(string $path, string $bytes): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        ssh_proof_fail("could not create $parent");
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        ssh_proof_fail("could not write $path");
    }
}

function ssh_proof_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') ssh_proof_remove($path . '/' . $child);
    }
    @rmdir($path);
}

function ssh_proof_run(array $command, ?string $cwd = null, bool $allowFailure = false): string {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) ssh_proof_fail('could not start fixture process');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 && !$allowFailure) {
        ssh_proof_fail('fixture command failed (' . $exit . '): ' . implode(' ', $command) . "\n" . $stderr . $stdout);
    }
    return trim($exit !== 0 ? $stderr . $stdout : $stdout);
}

function ssh_proof_git(string $repo, array $args, bool $allowFailure = false): string {
    return ssh_proof_run(array_merge(['git', '-C', $repo], array_map('strval', $args)), null, $allowFailure);
}

function ssh_proof_copy_tree(string $source, string $target): void {
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) ssh_proof_fail("could not create $target");
    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $from = $source . '/' . $name;
        $to = $target . '/' . $name;
        if (is_dir($from) && !is_link($from)) {
            ssh_proof_copy_tree($from, $to);
        } elseif (is_link($from)) {
            if (!symlink((string) readlink($from), $to)) ssh_proof_fail("could not copy symlink $from");
        } elseif (!copy($from, $to)) {
            ssh_proof_fail("could not copy $from");
        }
    }
}

$tmp = sys_get_temp_dir() . '/environment-materializer-ssh-proof-' . bin2hex(random_bytes(6));
$repo = $tmp . '/source';
$production = $tmp . '/production';
$target = $tmp . '/target';
$bin = $tmp . '/bin';
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    ssh_proof_remove($tmp);
});

try {
    mkdir($repo . '/state', 0700, true);
    foreach ([
        'capabilities/adapter-authorities.json',
        'capabilities/platform.json',
        'core/disposition.json',
        'core/manifest.json',
        'profiles.json',
    ] as $relative) {
        ssh_proof_write(
            $repo . '/platform/adapter-library/' . $relative,
            (string) file_get_contents($root . '/platform/adapter-library/' . $relative)
        );
    }
    $package = $repo . '/adapter-packages/ssh-proof/package';
    // Keep this fixture generic and self-contained: one excluded package owns
    // one explicit tombstone and makes no product certification claim.
    ssh_proof_write($package . '/manifest.json', json_encode([
        'deletions' => ['post:attachment' => [
            'cascades' => ['postmeta', 'post_revisions', 'term_relationships'], 'guards' => [],
        ]],
        'name' => 'ssh-proof', 'spec_version' => 2,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    ssh_proof_write($package . '/disposition.json', json_encode([
        'capabilities' => [
            'deletion_semantics' => ['supported' => [], 'unsupported' => ['all']],
            'entity_sections' => [],
            'field_sections' => [],
            'lifecycle_phases' => [],
            'operations' => ['test-only'],
        ],
        'default_authored_keyspaces' => [],
        'reason' => 'SSH environment regression fixture, not a product support claim.',
        'status' => 'excluded',
        'supported_versions' => ['fixture' => true],
        'unsupported' => [[
            'operation' => 'all',
            'reason' => 'Excluded fixtures are never production-ready.',
            'surface' => 'production',
        ]],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    $deletionUuid = '00000000-0000-4000-8000-000000000001';
    ssh_proof_write($repo . '/state/deletions/' . $deletionUuid . '.json', json_encode([
        'expected_hash' => hash('sha256', 'prior-attachment'),
        'expected_revision' => hash('sha256', 'prior-revision'),
        'format' => 'wprism-deletion/v1', 'kind' => 'post',
        'source_path' => 'posts/attachment/' . $deletionUuid . '--photo.md',
        'type' => 'attachment', 'uuid' => $deletionUuid,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    ssh_proof_write($repo . '/site.wprism.json', json_encode([
        'manifests' => ['ssh-proof'],
        'policy' => [
            'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
            'post_types' => [], 'taxonomies' => [],
        ],
        'spec_version' => 2,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    ssh_proof_run(['git', 'init', '-b', 'feature'], $repo);
    ssh_proof_run(['git', 'config', 'user.email', 'wprism-test@example.invalid'], $repo);
    ssh_proof_run(['git', 'config', 'user.name', 'WPrism SSH Proof'], $repo);
    ssh_proof_run(['git', 'add', '.'], $repo);
    ssh_proof_run(['git', 'commit', '-qm', 'fixture base'], $repo);
    $base = ssh_proof_git($repo, ['rev-parse', 'HEAD']);
    ssh_proof_run(['git', '-C', $repo, 'branch', 'production']);
    ssh_proof_write($repo . '/README.md', "feature-only\n");
    ssh_proof_run(['git', '-C', $repo, 'add', 'README.md']);
    ssh_proof_run(['git', '-C', $repo, 'commit', '-qm', 'fixture feature']);
    $feature = ssh_proof_git($repo, ['rev-parse', 'HEAD']);
    ssh_proof_run(['git', 'clone', '--quiet', '--branch', 'production', $repo, $production]);
    ssh_proof_run(['git', 'clone', '--quiet', '--branch', 'production', $repo, $target]);
    mkdir($production . '/wp', 0700, true);
    mkdir($target . '/wp', 0700, true);
    mkdir($target . '/.wprism/artifacts', 0700, true);
    mkdir($target . '/.wprism/checkpoints', 0700, true);
    $productionCommit = ssh_proof_git($production, ['rev-parse', 'HEAD']);
    ssh_proof_ok($productionCommit === $base, 'production clone is the exact clean production commit');

    require_once $root . '/agent/src/Kernel/Canon.php';
    require_once $root . '/cli/src/Refresh/RefreshPlan.php';
    $compiledProduction = RefreshPlan::compileGitWorktree($production, $productionCommit, 'production-code');
    $export = $compiledProduction;
    unset($export['format'], $export['commit'], $export['label']);
    $export['format'] = 'wprism-refresh-production/v1';
    $export['snapshot_hash'] = hash('sha256', Canon::encode($export));
    $exportPath = $tmp . '/production-export.json';
    ssh_proof_write($exportPath, Canon::encode($export) . "\n");

    $artifact = [
        'deletions' => [], 'effects_inventory' => [], 'format' => 'wprism-compiled-repository/v1',
        'revision_hash' => hash('sha256', 'ssh-proof-state'), 'tree' => [], 'uploads_inventory' => [],
    ];
    $artifact['artifact_hash'] = hash('sha256', json_encode(
        $artifact,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
    $artifactPath = $tmp . '/artifact.json';
    ssh_proof_write($artifactPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    $summaryPath = $tmp . '/compile-summary.json';
    $summary = ['artifact_hash' => $artifact['artifact_hash'], 'revision_hash' => $artifact['revision_hash']];
    ssh_proof_write($summaryPath, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    // The complete envelope agent/src/Apply/Apply.php emits, not just the buckets
    // this proof reads: convergence refuses an incomplete plan (issue #3384).
    $planPath = $tmp . '/plan.json';
    ssh_proof_write($planPath, json_encode([
        'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
        'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
        'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
        'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
        'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
        'skipped_user_meta' => [],
        'unchanged' => [], 'update' => [], 'uploads_inventory' => [], 'warnings' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    mkdir($bin, 0700, true);
    $wp = $bin . '/wp';
    ssh_proof_write($wp, <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$args = $argv; array_shift($args);
$log = (string) getenv('WPRISM_SSH_PROOF_WP_LOG');
file_put_contents($log, json_encode($args, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$wprism = array_search('wprism', $args, true);
$sub = $wprism === false ? '' : (string) ($args[$wprism + 1] ?? '');
$find = static function (string $prefix) use ($args): ?string {
    foreach ($args as $arg) if (str_starts_with((string) $arg, $prefix)) return substr((string) $arg, strlen($prefix));
    return null;
};
if ($sub === 'refresh-export') { echo file_get_contents((string) getenv('WPRISM_SSH_PROOF_EXPORT')); exit(0); }
if ($sub === 'compile') {
    $out = $find('--out=');
    if (!is_string($out) || $out === '' || !copy((string) getenv('WPRISM_SSH_PROOF_ARTIFACT'), $out)) exit(1);
    echo file_get_contents((string) getenv('WPRISM_SSH_PROOF_SUMMARY')); exit(0);
}
if ($sub === 'plan') { echo file_get_contents((string) getenv('WPRISM_SSH_PROOF_PLAN')); exit(0); }
if ($sub === 'checkpoint-seal') {
    $output = $find('--output=');
    if (!is_string($output) || $output === '') exit(2);
    $input = stream_get_contents(STDIN);
    if (!is_string($input) || $input === '' || file_put_contents($output, $input, LOCK_EX) === false) exit(1);
    exit(0);
}
if ($wprism === false && in_array('eval', $args, true)) {
    foreach ($args as $arg) {
        if (str_contains((string) $arg, 'get_option')) {
            // Source URL binding read: home then uploads, one per line (the
            // materializer rebinds a rehearsal target off its restored snapshot).
            echo "http://source.example:9600\nhttp://source.example:9600/wp-content/uploads\n"; exit(0);
        }
    }
}
if (($sub === 'db' && $wprism !== false && (($args[$wprism + 2] ?? '') === 'export'))
    || ($wprism === false && (($args[0] ?? '') === 'db') && (($args[1] ?? '') === 'export'))) {
    $path = (string) ($wprism === false ? ($args[2] ?? '') : ($args[$wprism + 3] ?? ''));
    if ($path === '-') { echo "wprism frozen checkpoint\n"; exit(0); }
    if ($path === '' || file_put_contents($path, "wprism frozen checkpoint\n", LOCK_EX) === false) exit(1);
    echo "Exported to '$path'\n"; exit(0);
}
if ($sub === 'apply') {
    $summary = json_decode(file_get_contents((string) getenv('WPRISM_SSH_PROOF_SUMMARY')), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(['artifact' => ['hash' => $summary['artifact_hash'], 'revision' => $summary['revision_hash']]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
exit(0);
PHP);
    chmod($wp, 0700);
    $ssh = $bin . '/ssh';
    ssh_proof_write($ssh, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
remote="${!#}"
exec /bin/sh -c "$remote"
SH);
    chmod($ssh, 0700);
    $wpLog = $tmp . '/wp.log';
    ssh_proof_write($wpLog, '');
    $provider = $tmp . '/provider.php';
    $sourceLog = $tmp . '/source-provider.log';
    $targetLog = $tmp . '/target-provider.log';
    $providerState = $tmp . '/provider-state.json';
    ssh_proof_write($sourceLog, ''); ssh_proof_write($targetLog, '');
    ssh_proof_write($providerState, json_encode(['mutation_state' => 'released'], JSON_THROW_ON_ERROR));
    $semanticHash = (string) $export['snapshot_hash'];
    ssh_proof_write($provider, <<<'PHP'
<?php
declare(strict_types=1);
$environment = (string) ($argv[1] ?? '');
$log = (string) ($argv[2] ?? '');
$semantic = (string) ($argv[3] ?? '');
$statePath = (string) ($argv[4] ?? '');
$providerState = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : [];
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($log, json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$hash = static fn(string $v): string => hash('sha256', $v);
$target = $environment === 'branch';
$identity = [
    'environment_identity' => $target ? 'target-environment-0001' : 'source-environment-0001',
    'lease_generation' => 1, 'lease_id' => $target ? 'target-lease-0001' : 'source-lease-0001',
    'ownership_receipt_sha256' => $hash($environment . '-owner'),
    'resource_id' => $target ? 'target-resource-0001' : 'source-resource-0001',
    'url' => $target ? 'https://branch.example.test' : 'https://production.example.test',
];
$action = (string) ($request['action'] ?? '');
$input = is_array($request['input'] ?? null) ? $request['input'] : [];
$snapshot = [
    'database_sha256' => $hash('db'), 'lease_generation' => 1, 'lease_id' => 'source-lease-0001',
    'lease_receipt_sha256' => $hash('source-lease'), 'media_sha256' => $hash('media'),
    'retention_receipt_sha256' => $hash('retention'), 'semantic_snapshot_sha256' => $semantic,
    'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? $input['snapshot_session_id'] ?? ''),
    'snapshot_set_id' => (string) ($input['expected_snapshot_set_id'] ?? 'snapshot-set-0001'),
    'snapshot_set_receipt_sha256' => $hash('snapshot-set'), 'source_identity' => 'source-environment-0001',
];
$fenceOwner = (string) ($input['mutation_owner'] ?? $input['expected_mutation_owner'] ?? '');
$fenceState = $action === 'mutation-read' ? (string) ($providerState['mutation_state'] ?? 'released') : ($action === 'mutation-release' ? 'released' : 'held');
if ($action === 'mutation-acquire') {
    $providerState['mutation_state'] = 'held';
    file_put_contents($statePath, json_encode($providerState, JSON_THROW_ON_ERROR), LOCK_EX);
} elseif ($action === 'mutation-release') {
    $providerState['mutation_state'] = 'released';
    file_put_contents($statePath, json_encode($providerState, JSON_THROW_ON_ERROR), LOCK_EX);
}
$fence = $identity + [
    'mutation_generation' => 1, 'mutation_id' => 'target-mutation-0001', 'mutation_owner' => $fenceOwner,
    'mutation_receipt_sha256' => $hash('mutation-' . $fenceOwner), 'state' => $fenceState,
];
$result = match ($action) {
    'capabilities' => ['capabilities' => [
        'environment.attach', 'environment.detach', 'environment.inspect',
        'environment.mutation.acquire', 'environment.mutation.read', 'environment.mutation.release',
        'environment.ttl', 'environment.ttl.read', 'environment.url.discover', 'environment.url.set',
        'operation.receipts', 'repository.materialize', 'snapshot.set.abort', 'snapshot.set.create',
        'snapshot.set.prepare', 'snapshot.set.read', 'snapshot.set.restore',
    ]],
    'inspect', 'attach', 'create' => $identity + ['presence' => 'present'],
    'snapshot-prepare' => [
        'lease_generation' => 1, 'lease_id' => 'source-lease-0001', 'lease_receipt_sha256' => $hash('source-lease'),
        'snapshot_session_id' => (string) ($input['snapshot_session_id'] ?? ''), 'source_identity' => 'source-environment-0001',
    ],
    'snapshot-create' => $snapshot,
    'snapshot-read' => $snapshot + ['immutable' => true],
    'snapshot-abort' => [
        'disposition' => 'aborted', 'lease_generation' => 1, 'lease_id' => 'source-lease-0001',
        'lease_receipt_sha256' => $hash('source-lease'), 'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
        'source_identity' => 'source-environment-0001',
    ],
    'snapshot-restore' => $identity + ['snapshot_set_id' => (string) ($input['snapshot_set_id'] ?? '')],
    'repository-materialize' => $identity + [
        'branch_commit' => (string) ($input['branch_commit'] ?? ''), 'repository_receipt_sha256' => $hash('repository'),
    ],
    'url-set' => $identity,
    'mutation-acquire', 'mutation-read', 'mutation-release' => $fence,
    'destroy', 'detach' => [
        'absence_proof_sha256' => $hash('absence'), 'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
        'environment_identity' => $identity['environment_identity'], 'lease_generation' => 1,
        'lease_id' => $identity['lease_id'], 'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'resource_id' => $identity['resource_id'],
    ],
    default => [],
};
$response = [
    'action' => $action, 'environment' => $environment, 'format' => 'wprism-branch-environment-provider-response/v1',
    'operation_id' => (string) ($request['operation_id'] ?? ''), 'provider' => ['id' => 'ssh-proof-provider', 'protocol' => 1],
    'result' => $result, 'status' => 'ok',
];
$normalize = static function (mixed $v) use (&$normalize): mixed {
    if (!is_array($v)) return $v;
    if (!array_is_list($v)) ksort($v, SORT_STRING);
    foreach ($v as $k => $x) $v[$k] = $normalize($x);
    return $v;
};
echo json_encode($normalize($response), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
PHP);
    chmod($provider, 0700);

    $overlay = $tmp . '/envs.json';
    $providerCommand = static fn(string $environment, string $log): array => [PHP_BINARY, $provider, $environment, $log, $semanticHash, $providerState];
    ssh_proof_write($overlay, json_encode(['envs' => [
        'production' => [
            'transport' => 'local', 'wp_path' => $production . '/wp', 'repo_path' => $production,
            'environment_provider' => ['command' => $providerCommand('production', $sourceLog), 'timeout_seconds' => 5],
        ],
        'branch' => [
            'transport' => 'ssh', 'host' => 'offline-ssh-fixture', 'wp_path' => $target . '/wp', 'repo_path' => $target,
            'environment_provider' => ['command' => $providerCommand('branch', $targetLog), 'timeout_seconds' => 5],
        ],
    ]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

    $oldPath = (string) getenv('PATH');
    putenv('PATH=' . $bin . ':' . $oldPath);
    putenv('WPRISM_SSH_PROOF_EXPORT=' . $exportPath);
    putenv('WPRISM_SSH_PROOF_ARTIFACT=' . $artifactPath);
    putenv('WPRISM_SSH_PROOF_SUMMARY=' . $summaryPath);
    putenv('WPRISM_SSH_PROOF_PLAN=' . $planPath);
    putenv('WPRISM_SSH_PROOF_WP_LOG=' . $wpLog);
    $wprism = $root . '/cli/wprism';
    $materialize = ssh_proof_run([
        PHP_BINARY, $wprism, '--envs-file=' . $overlay, 'env', 'materialize', 'branch',
        '--from', 'production', '--branch', 'feature', '--format=json',
    ], $repo, true);
    $materializeLines = preg_split('/\r?\n/', $materialize) ?: [];
    $materializeJson = null;
    for ($i = count($materializeLines) - 1; $i >= 0; $i--) {
        $candidate = trim(implode("\n", array_slice($materializeLines, $i)));
        if ($candidate === '') continue;
        try { $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
        if (is_array($decoded)) { $materializeJson = $decoded; break; }
    }
    ssh_proof_ok($materializeJson !== null, "public env materialize succeeded:\n$materialize");
    ssh_proof_ok(($materializeJson['mode'] ?? null) === 'attach', 'materialization receipt records attach mode');
    ssh_proof_ok(($materializeJson['outer_artifact_hash'] ?? null) === $artifact['artifact_hash'], 'materialization receipt retains frozen artifact identity');
    ssh_proof_ok(($materializeJson['state_revision'] ?? null) === $artifact['revision_hash']
        && array_key_exists('code_revision', $materializeJson)
        && $materializeJson['code_revision'] === null,
        'state-only release publishes code_revision=null exactly');
    $operation = (string) ($materializeJson['operation_id'] ?? '');
    ssh_proof_ok((bool) preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $operation), 'receipt exposes the operation-scoped identity');

    $providerRequests = static fn(string $path): array => array_values(array_filter(array_map(
        static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
    )));
    $sourceRequests = $providerRequests($sourceLog);
    $targetRequests = $providerRequests($targetLog);
    $sourceActions = array_column($sourceRequests, 'action');
    $targetActions = array_column($targetRequests, 'action');
    ssh_proof_ok(in_array('attach', $targetActions, true) && !in_array('create', $targetActions, true), 'attach mode never claims provider provisioning');
    ssh_proof_ok(in_array('snapshot-create', $sourceActions, true) && in_array('snapshot-read', $sourceActions, true), 'generic provider owns coherent snapshot creation/readback');

    $wpRequests = $providerRequests($wpLog);
    $wpLines = array_map(static fn(array $request): string => implode(' ', array_map('strval', $request)), $wpRequests);
    $compileLines = array_values(array_filter($wpLines, static fn(string $line): bool => str_contains($line, ' wprism compile ')));
    ssh_proof_ok(count($compileLines) === 2, 'target compile runs once for frozen materialization and once for final verification');
    ssh_proof_ok(count(array_filter($wpLines, static fn(string $line): bool => str_contains($line, ' promotion-begin '))) === 1, 'promotion-begin is issued once under the frozen owner');
    ssh_proof_ok(count(array_filter($wpLines, static fn(string $line): bool => str_contains($line, 'wprism apply '))) === 1, 'apply is issued once through the frozen callback');
    ssh_proof_ok(count(array_filter($wpLines, static fn(string $line): bool => str_contains($line, ' plan '))) === 1, 'final convergence checks the exact frozen artifact');
    ssh_proof_ok(str_contains($compileLines[0] ?? '', 'materialize-' . $operation . '.json'), 'initial compile path is operation-canonical');
    ssh_proof_ok(str_contains($compileLines[1] ?? '', 'materialize-verify-' . $operation . '.json'), 'verification compile path is operation-canonical');
    foreach ($wpLines as $line) {
        if (str_contains($line, 'promotion-begin') || str_contains($line, 'wprism apply ') || str_contains($line, ' lifecycle-')) {
            ssh_proof_ok(str_contains($line, 'wprism-env-promotion-' . $operation), 'promotion phase carries deterministic operation owner');
        }
    }

    $reap = ssh_proof_run([
        PHP_BINARY, $wprism, '--envs-file=' . $overlay, 'env', 'reap', 'branch', '--format=json',
    ], $repo, true);
    $reapLines = preg_split('/\r?\n/', $reap) ?: [];
    $reapReceipt = null;
    for ($i = count($reapLines) - 1; $i >= 0; $i--) {
        $candidate = trim(implode("\n", array_slice($reapLines, $i)));
        if ($candidate === '') continue;
        try { $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
        if (is_array($decoded)) { $reapReceipt = $decoded; break; }
    }
    ssh_proof_ok($reapReceipt !== null, "public env reap succeeded:\n$reap");
    ssh_proof_ok(($reapReceipt['disposition'] ?? null) === 'detached', 'public reap returns exact detach receipt');
    $targetRequests = $providerRequests($targetLog);
    $targetActions = array_column($targetRequests, 'action');
    ssh_proof_ok(in_array('detach', $targetActions, true) && !in_array('destroy', $targetActions, true), 'attached target reaps only through detach, never destroy');
    ssh_proof_ok($feature === ssh_proof_git($repo, ['rev-parse', 'HEAD']) && ssh_proof_git($repo, ['branch', '--show-current']) === 'feature', 'materialize and reap leave the source checkout/ref untouched');
    echo "PASS: SSH attach/materialize public-path regression\n";
} finally {
    foreach (['PATH', 'WPRISM_SSH_PROOF_EXPORT', 'WPRISM_SSH_PROOF_ARTIFACT', 'WPRISM_SSH_PROOF_SUMMARY', 'WPRISM_SSH_PROOF_PLAN', 'WPRISM_SSH_PROOF_WP_LOG'] as $name) putenv($name);
}
