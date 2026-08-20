<?php
/**
 * Public universal-preview product proof over the real host shell.
 *
 * The network, Git server, WordPress process, and provider are deterministic
 * fixtures. The product composition is not: cli/duo parses the public grammar,
 * drives a signed outbound origin demand, reconstructs a real
 * CloudCommittedOriginExport, runs the real Refresh semantic rebase, publishes
 * and removes the exact operation ref, then uses cmd_promote_frozen and a full
 * plan convergence observation before exact reap.
 *
 * usage: php universal-preview-command-checks.php <scratch-dir>
 */
declare(strict_types=1);

use Duo\Canon;
use Duo\Orchestrator\RefreshPlan;

require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 4) . '/cli/src/Refresh/RefreshPlan.php';
require_once dirname(__DIR__, 2) . '/lib/check.php';

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: universal-preview-command-checks.php <scratch-dir>\n");
    exit(2);
}
$scratch = realpath($scratch);
if (!is_string($scratch) || $scratch === '/') {
    throw new RuntimeException('universal-preview scratch must be an existing non-root directory');
}

function upc_write(string $path, string $bytes, int $mode = 0600): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException("could not create universal-preview directory '$parent'");
    }
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, $mode)) {
        throw new RuntimeException("could not write universal-preview fixture '$path'");
    }
}

/** @param list<string> $command @return array{exit:int,stderr:string,stdout:string} */
function upc_process(
    array $command,
    ?string $cwd = null,
    ?array $environment = null,
    ?string $stdin = null
): array {
    $stdinDescriptor = $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'];
    $process = proc_open(
        $command,
        [0 => $stdinDescriptor, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start universal-preview fixture process');
    }
    if ($stdin !== null) {
        if (fwrite($pipes[0], $stdin) !== strlen($stdin)) {
            throw new RuntimeException('could not write universal-preview fixture stdin');
        }
        fclose($pipes[0]);
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stderr' => $stderr, 'stdout' => $stdout];
}

/** @param list<string> $command */
function upc_run(array $command, ?string $cwd = null): string {
    $result = upc_process($command, $cwd);
    if ($result['exit'] !== 0) {
        throw new RuntimeException(
            'universal-preview fixture command failed: ' . implode(' ', $command)
                . "\n" . $result['stderr'] . $result['stdout']
        );
    }
    return trim($result['stdout']);
}

function upc_copy_tree(string $source, string $target): void {
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException("could not create copied directory '$target'");
    }
    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $from = $source . '/' . $name;
        $to = $target . '/' . $name;
        if (is_dir($from) && !is_link($from)) {
            upc_copy_tree($from, $to);
        } elseif (is_link($from)) {
            if (!symlink((string) readlink($from), $to)) {
                throw new RuntimeException("could not copy fixture symlink '$from'");
            }
        } elseif (!copy($from, $to)) {
            throw new RuntimeException("could not copy fixture file '$from'");
        }
    }
}

function upc_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') upc_remove($path . '/' . $name);
    }
    @rmdir($path);
}

function upc_drop_final_event(
    string $journalRoot,
    string $operationId,
    string $expectedEvent
): void {
    $events = glob($journalRoot . '/runs/' . $operationId . '/events/*.json') ?: [];
    sort($events, SORT_STRING);
    $path = $events === [] ? null : $events[count($events) - 1];
    $record = is_string($path)
        ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        : null;
    if (!is_string($path) || !is_array($record)
        || ($record['event'] ?? null) !== $expectedEvent
        || !unlink($path)) {
        throw new RuntimeException(
            "could not remove final '$expectedEvent' event for crash recovery fixture"
        );
    }
    if ($expectedEvent !== 'reaped') {
        return;
    }
    $runPath = $journalRoot . '/runs/' . $operationId . '/run.json';
    $run = json_decode((string) @file_get_contents($runPath), true, 512, JSON_THROW_ON_ERROR);
    $target = is_array($run) ? ($run['target_environment'] ?? null) : null;
    if (!is_string($target)) {
        throw new RuntimeException('could not recover the reaped run target for the crash fixture');
    }
    $indexPath = $journalRoot . '/targets/' . hash('sha256', $target) . '.json';
    $index = json_decode((string) @file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
    if (($index['format'] ?? null) !== 'duo-branch-environment-target-index/v1') {
        return;
    }
    if (($index['operation_id'] ?? null) !== $operationId
        || ($index['phase'] ?? null) !== 'reaped'
        || ($index['previous_reaped_operation_id'] ?? null) !== null) {
        throw new RuntimeException('environment target index cannot model the requested crash point');
    }
    // The real crash point precedes the immutable reap event, while the test
    // starts from a completed run and removes that event. Rewind the mutable
    // index to the exact pre-event phase so recovery is not asked to accept a
    // state that production publication can never create.
    $index['phase'] = 'active';
    $bytes = json_encode(
        $index,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
    if (file_put_contents($indexPath, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('could not rewind the environment target index crash fixture');
    }
}

function upc_latest_run_operation(string $journalRoot): string {
    $runs = glob($journalRoot . '/runs/*/run.json') ?: [];
    sort($runs, SORT_STRING);
    $path = $runs === [] ? null : $runs[count($runs) - 1];
    $run = is_string($path)
        ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        : null;
    $operationId = is_array($run) ? ($run['operation_id'] ?? null) : null;
    if (!is_string($operationId) || $operationId === '') {
        throw new RuntimeException('could not resolve latest journal operation');
    }
    return $operationId;
}

/** @return array<string,list<mixed>> */
function upc_plan(): array {
    return [
        'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
        'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
        'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
        'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
        'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
        'skipped_user_meta' => [], 'unchanged' => [], 'update' => [],
        'uploads_inventory' => [], 'warnings' => [],
    ];
}

/** @return ?array<string,mixed> */
function upc_last_json(string $bytes): ?array {
    $lines = preg_split('/\r?\n/', $bytes) ?: [];
    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $candidate = trim(implode("\n", array_slice($lines, $index)));
        if ($candidate === '') continue;
        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        if (is_array($decoded) && !array_is_list($decoded)) return $decoded;
    }
    return null;
}

/** @return list<array<string,mixed>> */
function upc_rows(string $path): array {
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($row) && !array_is_list($row)) $rows[] = $row;
    }
    return $rows;
}

/** @return list<list<mixed>> */
function upc_list_rows(string $path): array {
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($row) && array_is_list($row)) $rows[] = $row;
    }
    return $rows;
}

function upc_tree_contains(string $path, string $needle): bool {
    if (!is_dir($path)) return false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) continue;
        $bytes = file_get_contents($file->getPathname());
        if (is_string($bytes) && str_contains($bytes, $needle)) return true;
    }
    return false;
}

$root = dirname(__DIR__, 4);
$repo = $scratch . '/site';
$production = $scratch . '/production';
$remote = $scratch . '/remote.git';
$bin = $scratch . '/bin';
$keys = $scratch . '/keys';
$wpPath = $scratch . '/production-wp';
$timeline = $scratch . '/timeline.ndjson';
$wpLog = $scratch . '/origin-wp.ndjson';
$gitPushLog = $scratch . '/git-push.log';
$trigger = $scratch . '/origin-triggered';
$originCommitThreshold = $scratch . '/origin-commit-threshold';
$originExportPath = $scratch . '/origin-export.json';
$originManifestPath = $scratch . '/origin-manifest.json';
$targetState = $scratch . '/target-state.json';
$ready = $scratch . '/endpoint.ready';
$stop = $scratch . '/endpoint.stop';
$endpointOut = $scratch . '/endpoint.stdout';
$endpointErr = $scratch . '/endpoint.stderr';
$fenceState = $scratch . '/fence-state.json';
$responseMode = $scratch . '/response-mode';
$expectationsPath = $scratch . '/expectations.json';
foreach ([$repo, $bin, $keys, $wpPath] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException("could not create '$directory'");
    }
}

upc_copy_tree($root . '/manifests', $repo . '/manifests');
upc_remove($repo . '/manifests/dispositions.json');
upc_remove($repo . '/manifests/capabilities');
upc_write($repo . '/manifests/universal-preview.json', json_encode([
    'deletions' => ['post:attachment' => [
        'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
        'guards' => [],
    ]],
    'interpreter' => 'acf',
    'name' => 'universal-preview', 'spec_version' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
upc_write($repo . '/site.duo.json', json_encode([
    'manifests' => ['universal-preview'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'post_types' => [],
        'taxonomies' => [], 'term_meta' => (object) [],
    ],
    'spec_version' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$deletionUuid = '00000000-0000-4000-8000-000000000001';
upc_write($repo . '/state/deletions/' . $deletionUuid . '.json', json_encode([
    'expected_hash' => hash('sha256', 'universal-prior-attachment'),
    'expected_revision' => hash('sha256', 'universal-prior-revision'),
    'format' => 'duo-deletion/v1',
    'kind' => 'post',
    'source_path' => 'posts/attachment/' . $deletionUuid . '--photo.md',
    'type' => 'attachment',
    'uuid' => $deletionUuid,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
upc_run(['git', 'init', '-b', 'production'], $repo);
upc_run(['git', 'config', 'user.email', 'universal-preview@example.invalid'], $repo);
upc_run(['git', 'config', 'user.name', 'Universal Preview Fixture'], $repo);
upc_run(['git', 'add', '.'], $repo);
upc_run(['git', 'commit', '-qm', 'production fixture'], $repo);
$productionCommit = upc_run(['git', 'rev-parse', 'HEAD'], $repo);
upc_run(['git', 'checkout', '-b', 'feature/universal-preview'], $repo);
upc_write($repo . '/README.md', "feature candidate\n");
upc_run(['git', 'add', 'README.md'], $repo);
upc_run(['git', 'commit', '-qm', 'feature fixture'], $repo);
$sourceCommit = upc_run(['git', 'rev-parse', 'HEAD'], $repo);
$sourceBranch = upc_run(['git', 'branch', '--show-current'], $repo);

upc_run(['git', 'clone', '--quiet', '--branch', 'production', $repo, $production]);
$compiledProduction = RefreshPlan::compileGitWorktree(
    $production,
    $productionCommit,
    'production-code'
);
$export = $compiledProduction;
unset($export['format'], $export['commit'], $export['field_diff_policy'], $export['label']);
$export['format'] = 'duo-refresh-production/v1';
$export['deletions'] = [];
$export['warnings'] = [];
$export['snapshot_hash'] = hash('sha256', Canon::encode($export));
$exportBytes = Canon::encode($export);
$manifest = [
    'artifact_hash' => $export['repository']['artifact_hash'],
    'chunks' => [[
        'index' => 0,
        'offset' => 0,
        'sha256' => hash('sha256', $exportBytes),
        'size' => strlen($exportBytes),
    ]],
    'code_revision' => $export['repository']['code_revision'],
    'expected_production_commit' => $productionCommit,
    'export_sha256' => hash('sha256', $exportBytes),
    'export_size' => strlen($exportBytes),
    'format' => 'duo-cloud-origin-export-manifest/v1',
    'generation' => 1,
    'repository_revision_hash' => $export['repository']['revision_hash'],
    'snapshot_hash' => $export['snapshot_hash'],
];
$manifest['manifest_sha256'] = hash('sha256', Canon::encode($manifest));
upc_write($originExportPath, $exportBytes);
upc_write($originManifestPath, Canon::encode($manifest));
upc_write($originCommitThreshold, "1\n");

$artifact = [
    'deletions' => [], 'effects_inventory' => [], 'format' => 'duo-compiled-repository/v1',
    'revision_hash' => hash('sha256', 'universal-preview-state'), 'tree' => [],
    'uploads_inventory' => [],
];
$artifactBytes = json_encode(
    $artifact,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n";
$compileSummary = [
    'artifact_hash' => hash('sha256', $artifactBytes),
    'revision_hash' => $artifact['revision_hash'],
];

upc_run(['git', 'init', '--bare', $remote]);
$canonicalRemote = 'https://git.example.test/duo/universal-preview.git';
upc_run(['git', 'remote', 'add', 'duo-cloud', $canonicalRemote], $repo);
$realGit = upc_run(['which', 'git']);
$gitWrapper = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
real_git="${DUO_UPC_REAL_GIT:?}"
remote_path="${DUO_UPC_REMOTE_PATH:?}"
args=("$@")
rewrite=0
push=0
delete=0
for arg in "${args[@]}"; do
  if [[ "$arg" == "ls-remote" || "$arg" == "push" ]]; then rewrite=1; fi
  if [[ "$arg" == "push" ]]; then push=1; fi
  if [[ "$arg" == :refs/heads/duo-preview/* ]]; then delete=1; fi
done
if [[ "$push" == 1 && -n "${DUO_UPC_FAIL_PUSH_MARKER:-}" && ! -e "$DUO_UPC_FAIL_PUSH_MARKER" ]]; then
  : > "$DUO_UPC_FAIL_PUSH_MARKER"
  printf 'credential helper diagnostic: %s\n' "${DUO_UPC_GIT_SECRET:-missing}" >&2
  exit 17
fi
if [[ "$rewrite" == 1 ]]; then
  for i in "${!args[@]}"; do
    if [[ "${args[$i]}" == "duo-cloud" ]]; then args[$i]="$remote_path"; fi
  done
fi
if [[ "$push" == 1 && -n "${DUO_UPC_GIT_PUSH_LOG:-}" ]]; then
  if [[ "$delete" == 1 ]]; then
    printf 'delete\n' >> "$DUO_UPC_GIT_PUSH_LOG"
  else
    printf 'publish\n' >> "$DUO_UPC_GIT_PUSH_LOG"
  fi
fi
if [[ "$delete" == 1 && -n "${DUO_UPC_LOSE_DELETE_MARKER:-}" && ! -e "$DUO_UPC_LOSE_DELETE_MARKER" ]]; then
  "$real_git" "${args[@]}"
  : > "$DUO_UPC_LOSE_DELETE_MARKER"
  printf 'candidate cleanup response lost after remote deletion\n' >&2
  exit 18
fi
if [[ "$push" == 1 && "$delete" == 0 && -n "${DUO_UPC_LOSE_PUBLISH_MARKER:-}" && ! -e "$DUO_UPC_LOSE_PUBLISH_MARKER" ]]; then
  "$real_git" "${args[@]}"
  : > "$DUO_UPC_LOSE_PUBLISH_MARKER"
  printf 'candidate publication response lost after remote update\n' >&2
  exit 19
fi
exec "$real_git" "${args[@]}"
SH;
upc_write($bin . '/git', $gitWrapper, 0700);

$wpScript = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$args = $argv; array_shift($args);
file_put_contents((string) getenv('DUO_UPC_WP_LOG'), json_encode($args, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$duo = array_search('duo', $args, true);
$verb = is_int($duo) ? (string) ($args[$duo + 1] ?? '') : '';
if ($verb === 'origin-pair') {
    $code = stream_get_contents(STDIN);
    $codeLog = (string) getenv('DUO_UPC_PAIR_CODE_LOG');
    if ($codeLog !== '') file_put_contents($codeLog, $code, LOCK_EX);
    $mode = (string) getenv('DUO_UPC_PAIR_MODE');
    if ($mode === 'stderr-secret') {
        fwrite(STDERR, "DUO_PAIR_STDERR_SECRET_SENTINEL\n");
        exit(17);
    }
    if ($mode === 'stdout-secret') {
        echo "DUO_PAIR_STDOUT_SECRET_SENTINEL\n";
        exit(0);
    }
    if ($mode === 'valid-refusal-secret') {
        echo json_encode([
            'command' => 'origin-pair',
            'error' => 'pairing_refused',
            'format' => 'duo-command-refusal/v1',
            'message' => 'DUO_PAIR_VALID_REFUSAL_SECRET_SENTINEL',
            'ok' => false,
            'reason_code' => 'pairing_refused',
            'remediation' => 'DUO_PAIR_VALID_REFUSAL_REMEDIATION_SECRET',
            'secret_sentinel' => 'DUO_PAIR_VALID_REFUSAL_EXTRA_SECRET',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        exit(23);
    }
    $hash = static fn(string $value): string => hash('sha256', $value);
    echo json_encode([
        'format' => 'duo-cloud-origin-command/v1',
        'operation' => 'pair',
        'result' => [
            'origin_key_id' => $hash('universal origin pairing key'),
            'pair_attempt_id' => substr($hash('universal pair attempt'), 0, 32),
            'pairing' => null,
            'pairing_id' => 'pairing-fixture-0001',
            'phase' => 'pending',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if (in_array($verb, ['origin-rotate', 'origin-uninstall'], true)) {
    $operation = $verb === 'origin-rotate' ? 'rotate' : 'uninstall';
    $mode = (string) getenv('DUO_UPC_ORIGIN_LIFECYCLE_MODE');
    if ($mode === 'stderr-secret') {
        fwrite(STDERR, "DUO_ORIGIN_LIFECYCLE_STDERR_SECRET_SENTINEL\n");
        exit(31);
    }
    $hash = static fn(string $value): string => hash('sha256', $value);
    $document = [
        'format' => 'duo-cloud-origin-command/v1',
        'operation' => $operation,
        'result' => [
            'origin_key_id' => $hash('universal rotated origin key'),
            'pair_attempt_id' => substr($hash('universal lifecycle pair attempt'), 0, 32),
            'pairing' => [
                'demand_generation' => 1,
                'origin_generation' => 2,
                'service_key_id' => 'service-key-fixture-0001',
                'service_public_key_sha256' => $hash('universal service key'),
                'site_id' => 'site-fixture-0001',
                'tenant_id' => 'tenant-fixture-0001',
            ],
            'pairing_id' => 'pairing-fixture-0001',
            'phase' => $operation === 'rotate' ? 'paired' : 'revoked',
        ],
    ];
    if ($mode === 'extra-field-secret') {
        $document['secret_sentinel'] = 'DUO_ORIGIN_LIFECYCLE_JSON_SECRET_SENTINEL';
    }
    echo json_encode(
        $document,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
    exit(0);
}
if ($verb !== 'origin-export') exit(1);
if (getenv('DUO_UPC_ORIGIN_FAIL_SECRET') === '1') {
    echo json_encode([
        'command' => 'origin-export',
        'error' => 'origin_export_refused',
        'format' => 'duo-command-refusal/v1',
        'message' => 'DUO_ORIGIN_EXPORT_VALID_REFUSAL_SECRET',
        'ok' => false,
        'reason_code' => 'origin_export_refused',
        'remediation' => 'DUO_ORIGIN_EXPORT_REMEDIATION_SECRET',
        'secret_sentinel' => 'DUO_ORIGIN_EXPORT_EXTRA_SECRET',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "DUO_ORIGIN_EXPORT_STDERR_SECRET\n");
    exit(29);
}
if (getenv('DUO_UPC_ORIGIN_FAIL') === '1') exit(19);
$triggerPath = (string) getenv('DUO_UPC_ORIGIN_TRIGGER');
$thresholdPath = (string) getenv('DUO_UPC_ORIGIN_COMMIT_THRESHOLD');
$triggerCount = (int) trim((string) @file_get_contents($triggerPath));
$threshold = (int) trim((string) @file_get_contents($thresholdPath));
if ($triggerPath === '' || $thresholdPath === '' || $threshold < 1
    || file_put_contents($triggerPath, ($triggerCount + 1) . "\n", LOCK_EX) === false) {
    exit(18);
}
$committed = $triggerCount + 1 >= $threshold;
$hash = static fn(string $value): string => hash('sha256', $value);
$result = [
    'active_demand_generation' => 1,
    'after_demand_generation' => 1,
    'last_commit' => $committed ? [
        'commit_receipt_sha256' => $hash('universal commit receipt'),
        'demand_generation' => 1,
        'demand_id' => $hash('universal demand receipt'),
        'export_id' => $hash('universal exported artifact'),
        'manifest_sha256' => $hash('universal uploaded manifest'),
        'retention_deadline' => 2000003600,
        'snapshot_hash' => $hash('universal uploaded snapshot'),
    ] : null,
    'origin_generation' => 1,
    'phase' => $committed ? 'accepted' : 'demand_polling',
    'poll_after_seconds' => null,
    'poll_sequence' => 0,
    'remote_state' => $committed ? 'committed' : 'demanded',
    'site_id' => 'site-fixture-0001',
    'tenant_id' => 'tenant-fixture-0001',
];
echo json_encode([
    'format' => 'duo-cloud-origin-command/v1',
    'operation' => 'export',
    'result' => $result,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
PHP;
upc_write($bin . '/wp', $wpScript, 0700);
upc_write($wpLog, '');
upc_write($gitPushLog, '');
upc_write($targetState, json_encode(['files' => (object) []], JSON_THROW_ON_ERROR) . "\n");
upc_write($fenceState, json_encode(['state' => 'absent'], JSON_THROW_ON_ERROR) . "\n");
upc_write($responseMode, "\n");

$clientPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'universal-preview-client', true));
$clientSecret = sodium_crypto_sign_secretkey($clientPair);
$clientPublic = sodium_crypto_sign_publickey($clientPair);
$serverPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'universal-preview-server', true));
$serverSecret = sodium_crypto_sign_secretkey($serverPair);
$serverPublic = sodium_crypto_sign_publickey($serverPair);
$clientSecretPath = $keys . '/client-secret.key';
$clientPublicPath = $keys . '/client-public.key';
$serverSecretPath = $keys . '/server-secret.key';
$serverPublicPath = $keys . '/server-public.key';
upc_write($clientSecretPath, base64_encode($clientSecret) . "\n");
upc_write($clientPublicPath, base64_encode($clientPublic) . "\n");
upc_write($serverSecretPath, base64_encode($serverSecret) . "\n");
upc_write($serverPublicPath, base64_encode($serverPublic) . "\n");
sodium_memzero($clientSecret);
sodium_memzero($serverSecret);

$helper = $scratch . '/credential-helper';
upc_write($helper, "#!/bin/sh\nexit 1\n", 0700);
$helperHash = hash_file('sha256', $helper);
if (!is_string($helperHash)) throw new RuntimeException('could not hash fixture credential helper');
$expectations = [
    'credential_helper_sha256' => $helperHash,
    'environment' => 'preview',
    'plan' => upc_plan(),
    'repository_ref_prefix' => 'refs/heads/duo-preview/',
    'repository_remote_url' => $canonicalRemote,
    'request_key_id' => 'site-key-fixture-0001',
    'response_key_id' => 'control-key-fixture-0001',
    'site_id' => 'site-fixture-0001',
    'target' => [
        'environment_identity' => 'cloud-environment-identity-0001',
        'lease_generation' => 1,
        'lease_id' => 'cloud-lease-identity-0001',
        'mutation_generation' => 1,
        'mutation_id' => 'cloud-mutation-lease-0001',
        'mutation_receipt_sha256' => hash('sha256', 'cloud-mutation-held-generation-1'),
        'ownership_receipt_sha256' => hash('sha256', 'cloud-owner-generation-1'),
        'resource_id' => 'cloud-preview-slot-0001',
    ],
    'tenant_id' => 'tenant-fixture-0001',
    'universal' => [
        'artifact_bytes' => $artifactBytes,
        'compile_summary' => $compileSummary,
        'origin_commit_threshold_path' => $originCommitThreshold,
        'origin_export_path' => $originExportPath,
        'origin_manifest_path' => $originManifestPath,
        'origin_trigger_path' => $trigger,
        'target_state_path' => $targetState,
    ],
];
upc_write($expectationsPath, json_encode(
    $expectations,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . "\n");

$endpoint = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/cloud-preview-control-endpoint.php',
        $ready,
        $stop,
        $timeline,
        $expectationsPath,
        $clientPublicPath,
        $serverSecretPath,
        $fenceState,
        $responseMode,
    ],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $endpointOut, 'a'], 2 => ['file', $endpointErr, 'a']],
    $endpointPipes,
    $scratch,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($endpoint)) throw new RuntimeException('could not start universal preview endpoint');
register_shutdown_function(static function () use (&$endpoint, $stop): void {
    if (!is_resource($endpoint)) return;
    @touch($stop);
    @proc_terminate($endpoint);
    @proc_close($endpoint);
    $endpoint = null;
});
for ($attempt = 0; $attempt < 150 && !is_file($ready); $attempt++) usleep(20000);
$controlEndpoint = trim((string) @file_get_contents($ready));
if (preg_match('#^http://127\.0\.0\.1:[0-9]+/v1/preview/control$#D', $controlEndpoint) !== 1) {
    throw new RuntimeException('universal preview endpoint did not become ready: '
        . (string) @file_get_contents($endpointErr));
}

$overlay = $scratch . '/envs.json';
upc_write($overlay, json_encode(['envs' => [
    'production' => [
        'repo_path' => $repo,
        'transport' => 'local',
        'wp_path' => $wpPath,
    ],
    'preview' => [
        'cloud_preview' => [
            'control_endpoint' => $controlEndpoint,
            'lifecycle_endpoint' => str_replace('/control', '/lifecycle', $controlEndpoint),
            'request_key_id' => $expectations['request_key_id'],
            'request_signing_key' => $clientSecretPath,
            'response_key_id' => $expectations['response_key_id'],
            'response_public_key' => $serverPublicPath,
            'site_id' => $expectations['site_id'],
            'tenant_id' => $expectations['tenant_id'],
            'timeout_seconds' => 3,
        ],
        'repo_path' => '/srv/duo/repository',
        'transport' => 'cloud-preview',
    ],
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$environment = getenv();
if (!is_array($environment)) $environment = [];
$environment['PATH'] = $bin . ':' . ($environment['PATH'] ?? '');
$environment['DUO_TEST_MODE'] = '1';
$environment['DUO_UPC_ORIGIN_COMMIT_THRESHOLD'] = $originCommitThreshold;
$environment['DUO_UPC_ORIGIN_TRIGGER'] = $trigger;
$environment['DUO_UPC_REAL_GIT'] = $realGit;
$environment['DUO_UPC_REMOTE_PATH'] = $remote;
$environment['DUO_UPC_WP_LOG'] = $wpLog;
$pushFailure = $scratch . '/push-failed-once';
$secretSentinel = 'universal-private-git-credential-sentinel';
$environment['DUO_UPC_FAIL_PUSH_MARKER'] = $pushFailure;
$environment['DUO_UPC_GIT_SECRET'] = $secretSentinel;
$duo = $root . '/cli/duo';
$createArgs = [
    PHP_BINARY,
    $duo,
    '--envs-file=' . $overlay,
    'preview',
    'create',
    'preview',
    '--from=production',
    '--production-ref=production',
    '--new-branch=duo-preview/universal-candidate',
    '--ttl=3600',
    '--format=json',
];

$privateOverlaySentinel = 'DUO_PREVIEW_PRIVATE_PATH_SENTINEL';
$privateOverlay = $scratch . '/' . $privateOverlaySentinel . '/missing-envs.json';
$privateOverlayFailure = upc_process([
    PHP_BINARY,
    $duo,
    '--envs-file=' . $privateOverlay,
    'preview',
    'create',
    'preview',
    '--from=production',
    '--production-ref=production',
    '--new-branch=duo-preview/private-overlay-refusal',
    '--ttl=3600',
    '--format=json',
], $repo, $environment);
$privateOverlayDocument = json_decode(trim($privateOverlayFailure['stdout']), true);
duo_check($privateOverlayFailure['exit'] !== 0
    && $privateOverlayFailure['stderr'] === ''
    && is_array($privateOverlayDocument)
    && ($privateOverlayDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($privateOverlayDocument['reason_code'] ?? null) === 'preview_refused'
    && !str_contains(
        $privateOverlayFailure['stdout'] . $privateOverlayFailure['stderr'],
        $privateOverlaySentinel
    ),
    'public preview JSON refuses without exposing a private registry path');

$pairCode = "ABCDE-FGHIJ-KLMNO-PQRST\n";
$pairCodeLog = $scratch . '/pair-code.log';
$environment['DUO_UPC_PAIR_CODE_LOG'] = $pairCodeLog;
$pairArgs = [
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'pair', 'production', '--format=json',
];
$environment['DUO_UPC_PAIR_MODE'] = 'stderr-secret';
$pairStderr = upc_process($pairArgs, $repo, $environment, $pairCode);
$pairStderrDocument = json_decode(trim($pairStderr['stdout']), true);
duo_check($pairStderr['exit'] !== 0
    && $pairStderr['stderr'] === ''
    && is_array($pairStderrDocument)
    && ($pairStderrDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($pairStderrDocument['command'] ?? null) === 'origin-pair'
    && !str_contains($pairStderr['stdout'] . $pairStderr['stderr'], 'DUO_PAIR_STDERR_SECRET_SENTINEL'),
    'initial origin pairing turns target stderr into one value-free public JSON refusal');
$environment['DUO_UPC_PAIR_MODE'] = 'stdout-secret';
$pairStdout = upc_process($pairArgs, $repo, $environment, $pairCode);
$pairStdoutDocument = json_decode(trim($pairStdout['stdout']), true);
duo_check($pairStdout['exit'] !== 0
    && $pairStdout['stderr'] === ''
    && is_array($pairStdoutDocument)
    && ($pairStdoutDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($pairStdoutDocument['command'] ?? null) === 'origin-pair'
    && !str_contains($pairStdout['stdout'] . $pairStdout['stderr'], 'DUO_PAIR_STDOUT_SECRET_SENTINEL'),
    'initial origin pairing rejects malformed success output without exposing it');
$pairHumanArgs = [
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'pair', 'production',
];
$environment['DUO_UPC_PAIR_MODE'] = 'stderr-secret';
$pairHumanStderr = upc_process($pairHumanArgs, $repo, $environment, $pairCode);
$environment['DUO_UPC_PAIR_MODE'] = 'stdout-secret';
$pairHumanStdout = upc_process($pairHumanArgs, $repo, $environment, $pairCode);
duo_check($pairHumanStderr['exit'] !== 0
    && $pairHumanStderr['stdout'] === ''
    && !str_contains(
        $pairHumanStderr['stdout'] . $pairHumanStderr['stderr'],
        'DUO_PAIR_STDERR_SECRET_SENTINEL'
    )
    && $pairHumanStdout['exit'] !== 0
    && $pairHumanStdout['stdout'] === ''
    && !str_contains(
        $pairHumanStdout['stdout'] . $pairHumanStdout['stderr'],
        'DUO_PAIR_STDOUT_SECRET_SENTINEL'
    ),
    'human initial pairing keeps invalid target stdout and stderr private');
$environment['DUO_UPC_PAIR_MODE'] = 'valid-refusal-secret';
$pairValidRefusal = upc_process($pairArgs, $repo, $environment, $pairCode);
$pairValidRefusalDocument = json_decode(trim($pairValidRefusal['stdout']), true);
duo_check($pairValidRefusal['exit'] !== 0
    && $pairValidRefusal['stderr'] === ''
    && is_array($pairValidRefusalDocument)
    && ($pairValidRefusalDocument['reason_code'] ?? null) === 'origin_target_refused'
    && !str_contains(
        $pairValidRefusal['stdout'] . $pairValidRefusal['stderr'],
        'DUO_PAIR_VALID_REFUSAL'
    ),
    'a syntactically valid target refusal cannot publish target-owned fields or messages');
$environment['DUO_UPC_PAIR_MODE'] = 'success';
$paired = upc_process($pairArgs, $repo, $environment, $pairCode);
$pairedDocument = json_decode(trim($paired['stdout']), true);
$pairedHuman = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'pair', 'production',
], $repo, $environment, $pairCode);
$pairCalls = array_values(array_filter(
    upc_list_rows($wpLog),
    static function (array $args): bool {
        $duo = array_search('duo', $args, true);
        return is_int($duo) && ($args[$duo + 1] ?? null) === 'origin-pair';
    }
));
duo_check($paired['exit'] === 0
    && $paired['stderr'] === ''
    && is_array($pairedDocument)
    && ($pairedDocument['format'] ?? null) === 'duo-cloud-origin-command/v1'
    && ($pairedDocument['operation'] ?? null) === 'pair'
    && ($pairedDocument['result']['phase'] ?? null) === 'pending'
    && $pairedHuman['exit'] === 0
    && $pairedHuman['stderr'] === ''
    && str_contains($pairedHuman['stdout'], 'origin production pairing: pending')
    && str_contains($pairedHuman['stdout'], 'next: duo origin pair production --poll')
    && file_get_contents($pairCodeLog) === $pairCode
    && count($pairCalls) === 7
    && count(array_filter(
        $pairCalls,
        static fn(array $args): bool => in_array('--format=json', $args, true)
    )) === 7
    && !str_contains(json_encode($pairCalls, JSON_THROW_ON_ERROR), trim($pairCode)),
    'initial origin pairing inherits stdin, validates target JSON for both renderers, and keeps the device code out of argv');
unset($environment['DUO_UPC_PAIR_MODE'], $environment['DUO_UPC_PAIR_CODE_LOG']);

$rotateArgs = [
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'rotate', 'production', '--format=json',
];
$uninstallArgs = [
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'uninstall', 'production', '--format=json',
];
$beforeClosedRotation = count(upc_list_rows($wpLog));
$closedRotation = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'rotate', 'production', '--poll', '--format=json',
], $repo, $environment);
$closedRotationDocument = json_decode(trim($closedRotation['stdout']), true);
duo_check($closedRotation['exit'] !== 0
    && $closedRotation['stderr'] === ''
    && ($closedRotationDocument['reason_code'] ?? null) === 'invalid_arguments'
    && count(upc_list_rows($wpLog)) === $beforeClosedRotation,
    'origin rotation rejects pairing-only flags before target contact');

$environment['DUO_UPC_ORIGIN_LIFECYCLE_MODE'] = 'extra-field-secret';
$invalidRotate = upc_process($rotateArgs, $repo, $environment);
$invalidRotateDocument = json_decode(trim($invalidRotate['stdout']), true);
duo_check($invalidRotate['exit'] !== 0
    && $invalidRotate['stderr'] === ''
    && is_array($invalidRotateDocument)
    && ($invalidRotateDocument['reason_code'] ?? null) === 'origin_response_invalid'
    && !str_contains(
        $invalidRotate['stdout'] . $invalidRotate['stderr'],
        'DUO_ORIGIN_LIFECYCLE_JSON_SECRET_SENTINEL'
    ),
    'origin rotation rejects an extra target field without publishing its value');

$environment['DUO_UPC_ORIGIN_LIFECYCLE_MODE'] = 'stderr-secret';
$invalidUninstall = upc_process(array_values(array_filter(
    $uninstallArgs,
    static fn(string $argument): bool => $argument !== '--format=json'
)), $repo, $environment);
duo_check($invalidUninstall['exit'] !== 0
    && $invalidUninstall['stdout'] === ''
    && !str_contains(
        $invalidUninstall['stdout'] . $invalidUninstall['stderr'],
        'DUO_ORIGIN_LIFECYCLE_STDERR_SECRET_SENTINEL'
    ),
    'origin uninstall keeps target failure values private in human mode');

$environment['DUO_UPC_ORIGIN_LIFECYCLE_MODE'] = 'success';
$rotate = upc_process($rotateArgs, $repo, $environment);
$uninstall = upc_process($uninstallArgs, $repo, $environment);
$rotateDocument = json_decode(trim($rotate['stdout']), true);
$uninstallDocument = json_decode(trim($uninstall['stdout']), true);
$rotateHuman = upc_process(array_values(array_filter(
    $rotateArgs,
    static fn(string $argument): bool => $argument !== '--format=json'
)), $repo, $environment);
$uninstallHuman = upc_process(array_values(array_filter(
    $uninstallArgs,
    static fn(string $argument): bool => $argument !== '--format=json'
)), $repo, $environment);
$lifecycleCalls = array_values(array_filter(
    upc_list_rows($wpLog),
    static function (array $args): bool {
        $duo = array_search('duo', $args, true);
        return is_int($duo) && in_array(
            $args[$duo + 1] ?? null,
            ['origin-rotate', 'origin-uninstall'],
            true
        );
    }
));
duo_check($rotate['exit'] === 0
    && $rotate['stderr'] === ''
    && ($rotateDocument['operation'] ?? null) === 'rotate'
    && ($rotateDocument['result']['phase'] ?? null) === 'paired'
    && ($rotateDocument['result']['pairing']['origin_generation'] ?? null) === 2
    && $uninstall['exit'] === 0
    && $uninstall['stderr'] === ''
    && ($uninstallDocument['operation'] ?? null) === 'uninstall'
    && ($uninstallDocument['result']['phase'] ?? null) === 'revoked'
    && $rotateHuman['exit'] === 0
    && $rotateHuman['stderr'] === ''
    && str_contains($rotateHuman['stdout'], 'origin production pairing: paired')
    && $uninstallHuman['exit'] === 0
    && $uninstallHuman['stderr'] === ''
    && str_contains($uninstallHuman['stdout'], 'origin production pairing: revoked')
    && count($lifecycleCalls) === 6
    && count(array_filter(
        $lifecycleCalls,
        static fn(array $args): bool => in_array('--format=json', $args, true)
            && in_array('--skip-plugins', $args, true)
            && in_array('--skip-themes', $args, true)
            && count(array_filter($args, static fn(mixed $arg): bool =>
                is_string($arg)
                    && str_starts_with($arg, '--exec=')
                    && str_contains($arg, 'DUO_CONTROL_PLANE'))) === 1
    )) === 6,
    'public origin rotate and uninstall use exact value-screened protected connector commands');
unset($environment['DUO_UPC_ORIGIN_LIFECYCLE_MODE']);

$environment['DUO_UPC_ORIGIN_FAIL_SECRET'] = '1';
$secretOriginJson = upc_process($createArgs, $repo, $environment);
$secretOriginHuman = upc_process(array_values(array_filter(
    $createArgs,
    static fn(string $argument): bool => $argument !== '--format=json'
)), $repo, $environment);
duo_check($secretOriginJson['exit'] !== 0
    && $secretOriginJson['stderr'] === ''
    && ($secretOriginDocument = upc_last_json($secretOriginJson['stdout'])) !== null
    && ($secretOriginDocument['reason_code'] ?? null) === 'preview_refused'
    && !str_contains(
        $secretOriginJson['stdout'] . $secretOriginJson['stderr']
            . $secretOriginHuman['stdout'] . $secretOriginHuman['stderr'],
        'DUO_ORIGIN_EXPORT_'
    )
    && $secretOriginHuman['exit'] !== 0,
    'preview composition keeps a valid-looking origin export refusal private in JSON and human modes');
unset($environment['DUO_UPC_ORIGIN_FAIL_SECRET']);

$failedCreate = upc_process($createArgs, $repo, $environment);
$failedDocument = upc_last_json($failedCreate['stdout'] . $failedCreate['stderr']);
duo_check($failedCreate['exit'] !== 0
    && ($failedDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && is_file($pushFailure)
    && !str_contains($failedCreate['stdout'] . $failedCreate['stderr'], $secretSentinel)
    && !upc_tree_contains($repo . '/.git/duo-previews', $secretSentinel)
    && !upc_tree_contains($repo . '/.git/duo-environments', $secretSentinel),
    'a lost candidate publication refuses without exposing Git credentials in output or journals');
unset($environment['DUO_UPC_FAIL_PUSH_MARKER'], $environment['DUO_UPC_GIT_SECRET']);
$create = upc_process($createArgs, $repo, $environment);
$createOutput = $create['stdout'] . $create['stderr'];
$receipt = upc_last_json($createOutput);
$stdoutReceipt = json_decode(trim($create['stdout']), true);
$createOk = $create['exit'] === 0 && is_array($receipt)
    && is_array($stdoutReceipt) && $stdoutReceipt === $receipt
    && ($receipt['resumed'] ?? null) === true
    && $create['stderr'] === '';
duo_check($createOk, 'public duo preview create completes the universal composition'
    . ($createOk ? '' : ': ' . $createOutput));
if (!is_array($receipt)) throw new RuntimeException('universal preview returned no receipt');
duo_check(
    ($receipt['format'] ?? null) === 'duo-portable-preview-receipt/v1'
        && ($receipt['production_fidelity'] ?? null) === false
        && ($receipt['fidelity']['level'] ?? null) === 'portable-authored-state'
        && ($receipt['export_expected_production_commit'] ?? null) === $productionCommit,
    'the public receipt honestly binds portable fidelity and the exact production commit'
);
duo_check(
    upc_run(['git', 'branch', '--show-current'], $repo) === $sourceBranch
        && upc_run(['git', 'rev-parse', 'HEAD'], $repo) === $sourceCommit
        && upc_run(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $repo) === '',
    'real semantic rebase and remote publication leave the operator checkout byte-clean and unchanged'
);
duo_check(
    upc_run(['git', 'rev-parse', 'refs/heads/duo-preview/universal-candidate'], $repo)
        === ($receipt['branch_commit'] ?? null),
    'the preview consumes the exact real Refresh candidate branch commit'
);
$remoteRefs = upc_run(['git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)', 'refs/heads/duo-preview']);
duo_check($remoteRefs === '', 'the deterministic publication ref is absent after durable service sync');

$wpRows = upc_list_rows($wpLog);
$originCalls = array_values(array_filter($wpRows, static function (array $args): bool {
    $duo = array_search('duo', $args, true);
    return is_int($duo) && ($args[$duo + 1] ?? null) === 'origin-export';
}));
duo_check(count($originCalls) === 3
    && in_array('--skip-plugins', $originCalls[2], true)
    && in_array('--skip-themes', $originCalls[2], true)
    && count(array_filter($originCalls[2], static fn(mixed $arg): bool =>
        is_string($arg) && str_starts_with($arg, '--exec=') && str_contains($arg, 'DUO_CONTROL_PLANE'))) === 1,
    'one demanded export is triggered through the isolated outbound OriginCommand path');

$timelineCount = count(upc_rows($timeline));
$recoveredCreate = upc_process($createArgs, $repo, $environment);
$recoveredReceipt = upc_last_json($recoveredCreate['stdout'] . $recoveredCreate['stderr']);
duo_check($recoveredCreate['exit'] === 0
    && ($recoveredReceipt['resumed'] ?? null) === true
    && ($recoveredReceipt['receipt_sha256'] ?? null) === ($receipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $timelineCount
    && count(upc_list_rows($wpLog)) === count($wpRows),
    'an exact public create retry replays the durable completion without remote or target work');

$publicOrigin = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'origin', 'export', 'production', '--format=json',
], $repo, $environment);
$publicOriginDocument = upc_last_json($publicOrigin['stdout'] . $publicOrigin['stderr']);
$allOriginCalls = array_values(array_filter(
    upc_list_rows($wpLog),
    static function (array $args): bool {
        $duo = array_search('duo', $args, true);
        return is_int($duo) && ($args[$duo + 1] ?? null) === 'origin-export';
    }
));
duo_check($publicOrigin['exit'] === 0
    && ($publicOriginDocument['format'] ?? null) === 'duo-cloud-origin-command/v1'
    && ($publicOriginDocument['operation'] ?? null) === 'export'
    && ($publicOriginDocument['result']['phase'] ?? null) === 'accepted'
    && count($allOriginCalls) === 4,
    'public duo origin export uses the same typed, isolated exporter validation path');

$events = upc_rows($timeline);
$actions = array_map(static fn(array $row): string =>
    (string) (($row['kind'] ?? '') . ':' . ($row['action'] ?? '')), $events);
$syncAt = array_search('provider:repository-sync', $actions, true);
$materializeAt = array_search('provider:repository-materialize', $actions, true);
$acquireAt = array_search('provider:mutation-acquire', $actions, true);
duo_check(is_int($acquireAt) && is_int($syncAt) && is_int($materializeAt)
    && $acquireAt < $syncAt && $syncAt < $materializeAt,
    'candidate transfer is service-synced under the held fence before repository materialization');
$controlPayloads = array_values(array_filter(array_map(
    static fn(array $row): mixed => ($row['kind'] ?? null) === 'control' ? ($row['payload'] ?? null) : null,
    $events
), 'is_array'));
$verbs = [];
foreach ($controlPayloads as $payload) {
    $args = $payload['input']['argv'] ?? [];
    $duoAt = is_array($args) ? array_search('duo', $args, true) : false;
    if (is_int($duoAt)) $verbs[] = (string) ($args[$duoAt + 1] ?? '');
}
duo_check(in_array('compile', $verbs, true) && in_array('apply', $verbs, true)
    && in_array('plan', $verbs, true),
    'the public portable path executes real compile, frozen apply, and full-plan convergence');

$samePreviewIdentity = static fn(array $transition, array $created): bool =>
    ($transition['environment_identity'] ?? null) === ($created['environment_identity'] ?? null)
    && ($transition['lease_generation'] ?? null) === ($created['lease_generation'] ?? null)
    && ($transition['lease_id'] ?? null) === ($created['lease_id'] ?? null)
    && ($transition['ownership_receipt_sha256'] ?? null)
        === ($created['ownership_receipt_sha256'] ?? null)
    && ($transition['resource_id'] ?? null) === ($created['resource_id'] ?? null)
    && ($transition['url'] ?? null) === ($created['url'] ?? null);

$sleepTimelineStart = count(upc_rows($timeline));
$sleep = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'sleep', 'preview', '--format=json',
], $repo, $environment);
$sleepReceipt = upc_last_json($sleep['stdout'] . $sleep['stderr']);
duo_check($sleep['exit'] === 0
    && $sleep['stderr'] === ''
    && is_array($sleepReceipt)
    && ($sleepReceipt['format'] ?? null) === 'duo-cloud-preview-sleep-transition/v1'
    && ($sleepReceipt['action'] ?? null) === 'sleep'
    && ($sleepReceipt['sleep_state'] ?? null) === 'asleep'
    && ($sleepReceipt['preview_operation_id'] ?? null) !== null
    && ($sleepReceipt['resumed'] ?? null) === false
    && $samePreviewIdentity($sleepReceipt, $receipt),
    'public preview sleep retains the exact generation identity while revoking its route and execution'
        . ($sleep['exit'] === 0 ? '' : ': ' . $sleep['stdout'] . $sleep['stderr']));
if (!is_array($sleepReceipt)) throw new RuntimeException('public sleep returned no receipt');

$sleepTimeline = count(upc_rows($timeline));
$sleepReplay = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'sleep', 'preview', '--format=json',
], $repo, $environment);
$sleepReplayReceipt = upc_last_json($sleepReplay['stdout'] . $sleepReplay['stderr']);
duo_check($sleepReplay['exit'] === 0
    && ($sleepReplayReceipt['resumed'] ?? null) === true
    && ($sleepReplayReceipt['receipt_sha256'] ?? null)
        === ($sleepReceipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $sleepTimeline,
    'an exact public sleep retry replays its receipt without provider contact');

$asleepCreateTimeline = count(upc_rows($timeline));
$asleepCreate = upc_process($createArgs, $repo, $environment);
$asleepCreateDocument = upc_last_json($asleepCreate['stdout'] . $asleepCreate['stderr']);
duo_check($asleepCreate['exit'] !== 0
    && ($asleepCreateDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($asleepCreateDocument['reason_code'] ?? null) === 'preview_refused'
    && count(upc_rows($timeline)) === $asleepCreateTimeline,
    'public preview create refuses stale readiness without implicitly waking or contacting the provider');

$wake = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'wake', 'preview', '--format=json',
], $repo, $environment);
$wakeReceipt = upc_last_json($wake['stdout'] . $wake['stderr']);
duo_check($wake['exit'] === 0
    && $wake['stderr'] === ''
    && is_array($wakeReceipt)
    && ($wakeReceipt['format'] ?? null) === 'duo-cloud-preview-sleep-transition/v1'
    && ($wakeReceipt['action'] ?? null) === 'wake'
    && ($wakeReceipt['sleep_state'] ?? null) === 'awake'
    && ($wakeReceipt['operation_id'] ?? null) === ($sleepReceipt['operation_id'] ?? null)
    && ($wakeReceipt['preview_operation_id'] ?? null)
        === ($sleepReceipt['preview_operation_id'] ?? null)
    && ($wakeReceipt['resumed'] ?? null) === false
    && $samePreviewIdentity($wakeReceipt, $receipt),
    'public preview wake restores execution before the route under the same held sleep fence'
        . ($wake['exit'] === 0 ? '' : ': ' . $wake['stdout'] . $wake['stderr']));
if (!is_array($wakeReceipt)) throw new RuntimeException('public wake returned no receipt');

$wakeTimeline = count(upc_rows($timeline));
$wakeReplay = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'wake', 'preview', '--format=json',
], $repo, $environment);
$wakeReplayReceipt = upc_last_json($wakeReplay['stdout'] . $wakeReplay['stderr']);
duo_check($wakeReplay['exit'] === 0
    && ($wakeReplayReceipt['resumed'] ?? null) === true
    && ($wakeReplayReceipt['receipt_sha256'] ?? null)
        === ($wakeReceipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $wakeTimeline,
    'an exact public wake retry replays its receipt without provider contact');

$secondSleep = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'sleep', 'preview', '--format=json',
], $repo, $environment);
$secondSleepReceipt = upc_last_json($secondSleep['stdout'] . $secondSleep['stderr']);
duo_check($secondSleep['exit'] === 0
    && $secondSleep['stderr'] === ''
    && is_array($secondSleepReceipt)
    && ($secondSleepReceipt['sleep_state'] ?? null) === 'asleep'
    && ($secondSleepReceipt['operation_id'] ?? null) !== ($sleepReceipt['operation_id'] ?? null)
    && ($secondSleepReceipt['preview_operation_id'] ?? null)
        === ($sleepReceipt['preview_operation_id'] ?? null)
    && $samePreviewIdentity($secondSleepReceipt, $receipt),
    'a second public sleep starts a new durable cycle for the same retained generation'
        . ($secondSleep['exit'] === 0 ? '' : ': ' . $secondSleep['stdout'] . $secondSleep['stderr']));
if (!is_array($secondSleepReceipt)) throw new RuntimeException('second public sleep returned no receipt');

$asleepReapTimeline = count(upc_rows($timeline));
$reap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$reapReceipt = upc_last_json($reap['stdout'] . $reap['stderr']);
$reapOk = $reap['exit'] === 0 && is_array($reapReceipt)
    && ($reapReceipt['disposition'] ?? null) === 'already-absent'
    && ($reapReceipt['environment_identity'] ?? null) === ($receipt['environment_identity'] ?? null)
    && ($reapReceipt['resource_id'] ?? null) === ($receipt['resource_id'] ?? null);
duo_check($reapOk, 'public duo preview reap destroys the asleep generation under its held sleep fence'
    . ($reapOk ? '' : ': ' . $reap['stdout'] . $reap['stderr']));

$sleepLifecycleRows = array_values(array_filter(
    array_slice(upc_rows($timeline), $sleepTimelineStart),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['replayed'] ?? null) === false
        && in_array($row['action'] ?? null, [
            'destroy', 'mutation-acquire', 'mutation-release', 'sleep', 'wake',
        ], true)
));
$sleepRows = array_values(array_filter(
    $sleepLifecycleRows,
    static fn(array $row): bool => ($row['action'] ?? null) === 'sleep'
));
$wakeRows = array_values(array_filter(
    $sleepLifecycleRows,
    static fn(array $row): bool => ($row['action'] ?? null) === 'wake'
));
$destroyRows = array_values(array_filter(
    $sleepLifecycleRows,
    static fn(array $row): bool => ($row['action'] ?? null) === 'destroy'
));
$sleepOwner = 'duo-env-sleep-' . ($receipt['operation_id'] ?? '');
duo_check(count($sleepRows) === 2
    && count($wakeRows) === 1
    && count($destroyRows) === 1
    && ($sleepRows[0]['operation_id'] ?? null) === ($sleepReceipt['operation_id'] ?? null)
    && ($wakeRows[0]['operation_id'] ?? null) === ($sleepReceipt['operation_id'] ?? null)
    && ($sleepRows[1]['operation_id'] ?? null) === ($secondSleepReceipt['operation_id'] ?? null)
    && ($destroyRows[0]['operation_id'] ?? null) === ($secondSleepReceipt['operation_id'] ?? null)
    && ($sleepRows[0]['mutation_owner'] ?? null) === $sleepOwner
    && ($wakeRows[0]['mutation_owner'] ?? null) === $sleepOwner
    && ($sleepRows[1]['mutation_owner'] ?? null) === $sleepOwner
    && ($destroyRows[0]['mutation_owner'] ?? null) === $sleepOwner
    && ($sleepRows[0]['mutation_generation'] ?? null)
        === ($wakeRows[0]['mutation_generation'] ?? null)
    && ($sleepRows[0]['mutation_id'] ?? null) === ($wakeRows[0]['mutation_id'] ?? null)
    && ($sleepRows[0]['mutation_receipt_sha256'] ?? null)
        === ($wakeRows[0]['mutation_receipt_sha256'] ?? null)
    && ($sleepRows[1]['mutation_generation'] ?? null)
        === ($destroyRows[0]['mutation_generation'] ?? null)
    && ($sleepRows[1]['mutation_id'] ?? null) === ($destroyRows[0]['mutation_id'] ?? null)
    && ($sleepRows[1]['mutation_receipt_sha256'] ?? null)
        === ($destroyRows[0]['mutation_receipt_sha256'] ?? null)
    && ($sleepRows[1]['mutation_generation'] ?? 0)
        > ($sleepRows[0]['mutation_generation'] ?? 0)
    && ($sleepRows[0]['resource_actions'] ?? null)
        === ['route-revoke', 'execution-revoke']
    && ($wakeRows[0]['resource_actions'] ?? null)
        === ['execution-resume', 'route-restore']
    && ($destroyRows[0]['resource_actions'] ?? null)
        === ['route-verify-absent', 'execution-verify-revoked', 'state-delete'],
    'provider evidence preserves lineage and orders route/execution sleep, wake, and asleep destruction');

$asleepReapRows = array_slice(upc_rows($timeline), $asleepReapTimeline);
$asleepReapActions = array_values(array_map(
    static fn(array $row): mixed => ($row['kind'] ?? null) === 'provider'
        ? ($row['action'] ?? null) : null,
    $asleepReapRows
));
$destroyAt = array_search('destroy', $asleepReapActions, true);
duo_check(is_int($destroyAt)
    && count(array_filter($asleepReapActions, static fn(mixed $action): bool => $action === 'destroy')) === 1
    && !in_array('wake', $asleepReapActions, true)
    && !in_array('mutation-acquire', array_slice($asleepReapActions, $destroyAt + 1), true)
    && in_array('inspect', array_slice($asleepReapActions, $destroyAt + 1), true),
    'reap observes provider absence after one sleep-fenced destroy without waking or acquiring another fence');

$afterReapTimeline = count(upc_rows($timeline));
$repeatedReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$repeatedReapReceipt = upc_last_json($repeatedReap['stdout'] . $repeatedReap['stderr']);
duo_check($repeatedReap['exit'] === 0
    && ($repeatedReapReceipt['resumed'] ?? null) === true
    && ($repeatedReapReceipt['receipt_sha256'] ?? null) === ($reapReceipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $afterReapTimeline,
    'an exact public reap retry returns the same absence receipt without provider contact');

$targetStderrSentinel = 'universal-private-target-stderr-sentinel';
upc_write($responseMode, "target-apply-stderr-secret\n");
$targetStderrArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/target-stderr-refusal' : $argument,
    $createArgs
);
$targetStderr = upc_process($targetStderrArgs, $repo, $environment);
$targetStderrDocument = json_decode(trim($targetStderr['stdout']), true);
$targetStderrLines = array_values(array_filter(
    preg_split('/\r?\n/', trim($targetStderr['stdout'])) ?: [],
    static fn(string $line): bool => trim($line) !== ''
));
duo_check($targetStderr['exit'] !== 0
    && $targetStderr['stderr'] === ''
    && count($targetStderrLines) === 1
    && is_array($targetStderrDocument)
    && ($targetStderrDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($targetStderrDocument['command'] ?? null) === 'preview'
    && ($targetStderrDocument['reason_code'] ?? null) === 'preview_refused'
    && !str_contains($targetStderr['stdout'] . $targetStderr['stderr'], $targetStderrSentinel),
    'a signed target stderr secret becomes one clean public preview refusal');
upc_write($responseMode, "\n");
$targetStderrReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$targetStderrReapReceipt = upc_last_json($targetStderrReap['stdout'] . $targetStderrReap['stderr']);
duo_check($targetStderrReap['exit'] === 0
    && ($targetStderrReapReceipt['disposition'] ?? null) === 'destroyed',
    'the refused target-stderr generation remains exactly reapable');

upc_write($responseMode, "target-apply-stderr-secret\n");
$targetStderrHumanArgs = array_values(array_filter(array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/target-stderr-human-refusal' : $argument,
    $createArgs
), static fn(string $argument): bool => $argument !== '--format=json'));
$targetStderrHuman = upc_process($targetStderrHumanArgs, $repo, $environment);
duo_check($targetStderrHuman['exit'] !== 0
    && $targetStderrHuman['stdout'] === ''
    && $targetStderrHuman['stderr']
        === "duo: preview: frozen branch-environment promotion returned no receipt\n"
    && !str_contains(
        $targetStderrHuman['stdout'] . $targetStderrHuman['stderr'],
        $targetStderrSentinel
    ),
    'human preview keeps signed target stderr private and renders one host refusal');
upc_write($responseMode, "\n");
$targetStderrHumanReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$targetStderrHumanReapReceipt = upc_last_json(
    $targetStderrHumanReap['stdout'] . $targetStderrHumanReap['stderr']
);
duo_check($targetStderrHumanReap['exit'] === 0
    && ($targetStderrHumanReapReceipt['disposition'] ?? null) === 'destroyed',
    'the human target-stderr refusal remains exactly reapable');

$targetStdoutSentinel = 'universal-private-target-stdout-sentinel';
upc_write($responseMode, "target-stream-stdout-secret\n");
$targetStdoutArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/target-stdout-refusal' : $argument,
    $createArgs
);
$targetStdout = upc_process($targetStdoutArgs, $repo, $environment);
$targetStdoutDocument = json_decode(trim($targetStdout['stdout']), true);
$targetStdoutLines = array_values(array_filter(
    preg_split('/\r?\n/', trim($targetStdout['stdout'])) ?: [],
    static fn(string $line): bool => trim($line) !== ''
));
duo_check($targetStdout['exit'] !== 0
    && $targetStdout['stderr'] === ''
    && count($targetStdoutLines) === 1
    && is_array($targetStdoutDocument)
    && ($targetStdoutDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($targetStdoutDocument['command'] ?? null) === 'preview'
    && ($targetStdoutDocument['reason_code'] ?? null) === 'preview_refused'
    && !str_contains($targetStdout['stdout'] . $targetStdout['stderr'], $targetStdoutSentinel),
    'an early streamWp target stdout secret becomes one clean public preview refusal');
upc_write($responseMode, "\n");
$targetStdoutReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$targetStdoutReapReceipt = upc_last_json($targetStdoutReap['stdout'] . $targetStdoutReap['stderr']);
duo_check($targetStdoutReap['exit'] === 0
    && ($targetStdoutReapReceipt['disposition'] ?? null) === 'destroyed',
    'the refused target-stdout generation remains exactly reapable');

upc_write($responseMode, "target-stream-stdout-secret\n");
$targetStdoutHumanArgs = array_values(array_filter(array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/target-stdout-human-refusal' : $argument,
    $createArgs
), static fn(string $argument): bool => $argument !== '--format=json'));
$targetStdoutHuman = upc_process($targetStdoutHumanArgs, $repo, $environment);
duo_check($targetStdoutHuman['exit'] !== 0
    && $targetStdoutHuman['stdout'] === ''
    && $targetStdoutHuman['stderr']
        === "duo: preview: frozen branch-environment promotion returned no receipt\n"
    && !str_contains(
        $targetStdoutHuman['stdout'] . $targetStdoutHuman['stderr'],
        $targetStdoutSentinel
    ),
    'human preview keeps early target stdout private and renders one host refusal');
upc_write($responseMode, "\n");
$targetStdoutHumanReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$targetStdoutHumanReapReceipt = upc_last_json(
    $targetStdoutHumanReap['stdout'] . $targetStdoutHumanReap['stderr']
);
duo_check($targetStdoutHumanReap['exit'] === 0
    && ($targetStdoutHumanReapReceipt['disposition'] ?? null) === 'destroyed',
    'the human target-stdout refusal remains exactly reapable');

$cleanupLossMarker = $scratch . '/candidate-cleanup-response-lost';
$environment['DUO_UPC_GIT_PUSH_LOG'] = $gitPushLog;
$environment['DUO_UPC_LOSE_DELETE_MARKER'] = $cleanupLossMarker;
$cleanupRecoveryArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/candidate-cleanup-recovery' : $argument,
    $createArgs
);
$cleanupLoss = upc_process($cleanupRecoveryArgs, $repo, $environment);
$cleanupLossDocument = upc_last_json($cleanupLoss['stdout'] . $cleanupLoss['stderr']);
$remoteRefsAfterCleanupLoss = upc_run([
    'git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)', 'refs/heads/duo-preview',
]);
$pushesAfterCleanupLoss = file($gitPushLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$syncsAfterCleanupLoss = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'repository-sync'
        && ($row['replayed'] ?? false) === false
));
duo_check($cleanupLoss['exit'] !== 0
    && ($cleanupLossDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && is_file($cleanupLossMarker)
    && $remoteRefsAfterCleanupLoss === ''
    && $pushesAfterCleanupLoss === ['publish', 'delete'],
    'a crash after exact remote candidate deletion leaves durable synced cleanup work');
unset($environment['DUO_UPC_LOSE_DELETE_MARKER']);
$cleanupRecovered = upc_process($cleanupRecoveryArgs, $repo, $environment);
$cleanupRecoveredDocument = upc_last_json(
    $cleanupRecovered['stdout'] . $cleanupRecovered['stderr']
);
$pushesAfterCleanupRecovery = file($gitPushLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$syncsAfterCleanupRecovery = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'repository-sync'
        && ($row['replayed'] ?? false) === false
));
duo_check($cleanupRecovered['exit'] === 0
    && ($cleanupRecoveredDocument['format'] ?? null) === 'duo-portable-preview-receipt/v1'
    && $pushesAfterCleanupRecovery === $pushesAfterCleanupLoss
    && $syncsAfterCleanupRecovery === $syncsAfterCleanupLoss
    && upc_run([
        'git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)',
        'refs/heads/duo-preview',
    ]) === '',
    'cleanup lost-response recovery neither republishes nor repeats durable service sync');
unset($environment['DUO_UPC_GIT_PUSH_LOG']);
$cleanupRecoveryReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$cleanupRecoveryReapReceipt = upc_last_json(
    $cleanupRecoveryReap['stdout'] . $cleanupRecoveryReap['stderr']
);
duo_check($cleanupRecoveryReap['exit'] === 0
    && ($cleanupRecoveryReapReceipt['disposition'] ?? null) === 'destroyed',
    'the recovered candidate-cleanup generation remains exactly reapable');

$reapCleanupLossMarker = $scratch . '/candidate-reap-cleanup-response-lost';
upc_write($gitPushLog, '');
$environment['DUO_UPC_GIT_PUSH_LOG'] = $gitPushLog;
upc_write($responseMode, "repository-sync-foreign-commit\n");
$syncRefusalArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/reap-sync-refusal' : $argument,
    $createArgs
);
$syncRefusal = upc_process($syncRefusalArgs, $repo, $environment);
$syncRefusalDocument = upc_last_json($syncRefusal['stdout'] . $syncRefusal['stderr']);
$syncRefusalRemote = upc_run([
    'git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)',
    'refs/heads/duo-preview',
]);
duo_check($syncRefusal['exit'] !== 0
    && ($syncRefusalDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && str_starts_with($syncRefusalRemote, 'refs/heads/duo-preview/'),
    'a repository-sync refusal leaves its exact published operation ref for reap recovery');
$genericReapTimeline = count(upc_rows($timeline));
$genericReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'env', 'reap', 'preview', '--format=json',
], $repo, $environment);
duo_check($genericReap['exit'] !== 0
    && $genericReap['stderr'] === ''
    && ($genericReapDocument = upc_last_json($genericReap['stdout'])) !== null
    && ($genericReapDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($genericReapDocument['command'] ?? null) === 'env-reap'
    && ($genericReapDocument['reason_code'] ?? null) === 'environment_refused'
    && upc_run([
        'git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)',
        'refs/heads/duo-preview',
    ]) === $syncRefusalRemote
    && count(upc_rows($timeline)) === $genericReapTimeline,
    'generic env reap refuses a portable lifecycle before stranding its signed remote operation ref');
upc_write($responseMode, "\n");
$environment['DUO_UPC_LOSE_DELETE_MARKER'] = $reapCleanupLossMarker;
$reapCleanupLoss = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$reapCleanupLossDocument = upc_last_json(
    $reapCleanupLoss['stdout'] . $reapCleanupLoss['stderr']
);
$reapCleanupLossPushes = file(
    $gitPushLog,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
) ?: [];
$reapCleanupLossTimeline = count(upc_rows($timeline));
duo_check($reapCleanupLoss['exit'] !== 0
    && ($reapCleanupLossDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && is_file($reapCleanupLossMarker)
    && $reapCleanupLossPushes === ['publish', 'delete']
    && upc_run([
        'git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)',
        'refs/heads/duo-preview',
    ]) === '',
    'reap journals cleanup intent before an exact remote-delete response can be lost');
unset($environment['DUO_UPC_LOSE_DELETE_MARKER']);
$reapCleanupRecovered = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$reapCleanupRecoveredReceipt = upc_last_json(
    $reapCleanupRecovered['stdout'] . $reapCleanupRecovered['stderr']
);
$reapCleanupRecoveredPushes = file(
    $gitPushLog,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
) ?: [];
duo_check($reapCleanupRecovered['exit'] === 0
    && ($reapCleanupRecoveredReceipt['disposition'] ?? null) === 'destroyed'
    && $reapCleanupRecoveredPushes === $reapCleanupLossPushes
    && count(upc_rows($timeline)) > $reapCleanupLossTimeline,
    'reap recovers remote absence before destroying the exact provider generation');
$reapCleanupTimeline = count(upc_rows($timeline));
$reapCleanupRepeated = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$reapCleanupRepeatedReceipt = upc_last_json(
    $reapCleanupRepeated['stdout'] . $reapCleanupRepeated['stderr']
);
duo_check($reapCleanupRepeated['exit'] === 0
    && ($reapCleanupRepeatedReceipt['resumed'] ?? null) === true
    && ($reapCleanupRepeatedReceipt['receipt_sha256'] ?? null)
        === ($reapCleanupRecoveredReceipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $reapCleanupTimeline
    && (file($gitPushLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        === $reapCleanupRecoveredPushes,
    'repeated reap performs no Git or provider mutation after candidate absence is terminal');

$publicationLossMarker = $scratch . '/candidate-publication-response-lost';
upc_write($gitPushLog, '');
$environment['DUO_UPC_LOSE_PUBLISH_MARKER'] = $publicationLossMarker;
$publicationLossArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/reap-publication-loss' : $argument,
    $createArgs
);
$publicationLoss = upc_process($publicationLossArgs, $repo, $environment);
$publicationLossDocument = upc_last_json(
    $publicationLoss['stdout'] . $publicationLoss['stderr']
);
$publicationRefRow = upc_run([
    'git', '--git-dir=' . $remote, 'for-each-ref',
    '--format=%(refname) %(objectname)', 'refs/heads/duo-preview',
]);
$publicationRefParts = explode(' ', $publicationRefRow);
duo_check($publicationLoss['exit'] !== 0
    && ($publicationLossDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && is_file($publicationLossMarker)
    && count($publicationRefParts) === 2
    && str_starts_with($publicationRefParts[0], 'refs/heads/duo-preview/')
    && preg_match('/^[a-f0-9]{40}$/D', $publicationRefParts[1]) === 1,
    'a lost publication response retains enough signed intent for exact reap cleanup');
unset($environment['DUO_UPC_LOSE_PUBLISH_MARKER']);
$publicationCommit = $publicationRefParts[1] ?? '';
$publicationRef = $publicationRefParts[0] ?? '';
upc_run(['git', '--git-dir=' . $remote, 'update-ref', $publicationRef, $sourceCommit]);
$mismatchedReapTimeline = count(upc_rows($timeline));
$mismatchedReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$mismatchedReapDocument = upc_last_json($mismatchedReap['stdout'] . $mismatchedReap['stderr']);
duo_check($mismatchedReap['exit'] !== 0
    && ($mismatchedReapDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && upc_run(['git', '--git-dir=' . $remote, 'rev-parse', $publicationRef]) === $sourceCommit
    && count(upc_rows($timeline)) === $mismatchedReapTimeline,
    'reap refuses a publication ref changed away from its exact signed commit before provider mutation');
upc_run(['git', '--git-dir=' . $remote, 'update-ref', $publicationRef, $publicationCommit]);
$publicationLossReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$publicationLossReapReceipt = upc_last_json(
    $publicationLossReap['stdout'] . $publicationLossReap['stderr']
);
duo_check($publicationLossReap['exit'] === 0
    && ($publicationLossReapReceipt['disposition'] ?? null) === 'destroyed'
    && upc_run([
        'git', '--git-dir=' . $remote, 'for-each-ref', '--format=%(refname)',
        'refs/heads/duo-preview',
    ]) === '',
    'reap deletes the exact ref even when publication succeeded before its response was lost');
unset($environment['DUO_UPC_GIT_PUSH_LOG']);

$externalReapArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/external-ttl-reap' : $argument,
    $createArgs
);
$externalReapCreate = upc_process($externalReapArgs, $repo, $environment);
$externalReapCreateReceipt = upc_last_json(
    $externalReapCreate['stdout'] . $externalReapCreate['stderr']
);
duo_check($externalReapCreate['exit'] === 0
    && ($externalReapCreateReceipt['format'] ?? null) === 'duo-portable-preview-receipt/v1',
    'a completed preview remains reconcilable after service-owned TTL cleanup');
$lifecycleRoot = $repo . '/.git/duo-environments';
$withheldLifecycleRoot = $repo . '/.git/duo-environments-withheld';
if (!rename($lifecycleRoot, $withheldLifecycleRoot)) {
    throw new RuntimeException('could not withhold the lifecycle journal');
}
$missingLifecycleTimeline = count(upc_rows($timeline));
$missingLifecycleReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$missingLifecycleDocument = upc_last_json(
    $missingLifecycleReap['stdout'] . $missingLifecycleReap['stderr']
);
duo_check($missingLifecycleReap['exit'] !== 0
    && ($missingLifecycleDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && count(upc_rows($timeline)) === $missingLifecycleTimeline,
    'a durable materializer intent cannot fabricate local absence when lifecycle authority is missing');
upc_remove($lifecycleRoot);
if (!rename($withheldLifecycleRoot, $lifecycleRoot)) {
    throw new RuntimeException('could not restore the lifecycle journal');
}
$destroysBeforeExternalReap = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'destroy'
        && ($row['replayed'] ?? false) === false
));
upc_write($responseMode, "externally-reaped\n");
$externalReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$externalReapReceipt = upc_last_json($externalReap['stdout'] . $externalReap['stderr']);
$destroysAfterExternalReap = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'destroy'
        && ($row['replayed'] ?? false) === false
));
duo_check($externalReap['exit'] === 0
    && ($externalReapReceipt['disposition'] ?? null) === 'already-absent'
    && $destroysAfterExternalReap === $destroysBeforeExternalReap,
    'public reap turns the signed exact absent inspection into terminal local absence without another destroy');
$externalLifecycleOperation = $externalReapCreateReceipt['operation_id'] ?? null;
if (!is_string($externalLifecycleOperation) || $externalLifecycleOperation === '') {
    throw new RuntimeException('external-reap fixture has no lifecycle operation id');
}
$externalPreviewOperation = upc_latest_run_operation($repo . '/.git/duo-previews');
upc_drop_final_event($lifecycleRoot, $externalLifecycleOperation, 'reaped');
upc_drop_final_event($repo . '/.git/duo-previews', $externalPreviewOperation, 'reaped');
$externalAbsentRecoveryTimeline = count(upc_rows($timeline));
$externalAbsentRecovery = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$externalAbsentRecoveryReceipt = upc_last_json(
    $externalAbsentRecovery['stdout'] . $externalAbsentRecovery['stderr']
);
duo_check($externalAbsentRecovery['exit'] === 0
    && ($externalAbsentRecoveryReceipt['receipt_sha256'] ?? null)
        === ($externalReapReceipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $externalAbsentRecoveryTimeline,
    'a crash after durable provider-absence evidence reuses its exact response and converges without provider I/O');
$externalReapTimeline = count(upc_rows($timeline));
$externalReapRepeated = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$externalReapRepeatedReceipt = upc_last_json(
    $externalReapRepeated['stdout'] . $externalReapRepeated['stderr']
);
duo_check($externalReapRepeated['exit'] === 0
    && ($externalReapRepeatedReceipt['resumed'] ?? null) === true
    && ($externalReapRepeatedReceipt['receipt_sha256'] ?? null)
        === ($externalReapReceipt['receipt_sha256'] ?? null)
    && count(upc_rows($timeline)) === $externalReapTimeline,
    'repeating locally reconciled TTL reap is provider-mutation-free');
upc_write($responseMode, "\n");
$afterExternalReapArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/after-external-ttl-reap' : $argument,
    $createArgs
);
$createsBeforeExternalRetry = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'create'
        && ($row['replayed'] ?? false) === false
));
$afterExternalReapCreate = upc_process($afterExternalReapArgs, $repo, $environment);
$afterExternalReapReceipt = upc_last_json(
    $afterExternalReapCreate['stdout'] . $afterExternalReapCreate['stderr']
);
$createsAfterExternalRetry = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'create'
        && ($row['replayed'] ?? false) === false
));
duo_check($afterExternalReapCreate['exit'] === 0
    && ($afterExternalReapReceipt['format'] ?? null) === 'duo-portable-preview-receipt/v1'
    && $createsAfterExternalRetry === $createsBeforeExternalRetry + 1,
    'a changed public intent allocates again after TTL absence is reconciled into both journals');
$afterExternalReapCleanup = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$afterExternalReapCleanupReceipt = upc_last_json(
    $afterExternalReapCleanup['stdout'] . $afterExternalReapCleanup['stderr']
);
duo_check($afterExternalReapCleanup['exit'] === 0
    && ($afterExternalReapCleanupReceipt['disposition'] ?? null) === 'destroyed',
    'the generation allocated after reconciled TTL absence remains exactly reapable');

$changedExport = $export;
$changedExport['warnings'] = ['authored production state changed without a Git commit'];
unset($changedExport['snapshot_hash']);
$changedExport['snapshot_hash'] = hash('sha256', Canon::encode($changedExport));
$changedExportBytes = Canon::encode($changedExport);
$changedManifest = $manifest;
$changedManifest['chunks'][0]['sha256'] = hash('sha256', $changedExportBytes);
$changedManifest['chunks'][0]['size'] = strlen($changedExportBytes);
$changedManifest['export_sha256'] = hash('sha256', $changedExportBytes);
$changedManifest['export_size'] = strlen($changedExportBytes);
$changedManifest['snapshot_hash'] = $changedExport['snapshot_hash'];
unset($changedManifest['manifest_sha256']);
$changedManifest['manifest_sha256'] = hash('sha256', Canon::encode($changedManifest));
upc_write($originExportPath, $changedExportBytes);
upc_write($originManifestPath, Canon::encode($changedManifest));
@unlink($trigger);
$previewRunsBeforeSnapshotChange = glob($repo . '/.git/duo-previews/runs/*') ?: [];
$providerCreatesBeforeSnapshotChange = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'create'
        && ($row['replayed'] ?? false) === false
));
$snapshotReuse = upc_process($createArgs, $repo, $environment);
$snapshotReuseDocument = upc_last_json($snapshotReuse['stdout'] . $snapshotReuse['stderr']);
$previewRunsAfterSnapshotChange = glob($repo . '/.git/duo-previews/runs/*') ?: [];
$snapshotRuns = array_values(array_diff(
    $previewRunsAfterSnapshotChange,
    $previewRunsBeforeSnapshotChange
));
$snapshotVerifiedPaths = count($snapshotRuns) === 1
    ? (glob($snapshotRuns[0] . '/events/*-export-verified.json') ?: [])
    : [];
$snapshotVerified = count($snapshotVerifiedPaths) === 1
    ? json_decode((string) file_get_contents($snapshotVerifiedPaths[0]), true)
    : null;
$providerCreatesAfterSnapshotChange = count(array_filter(
    upc_rows($timeline),
    static fn(array $row): bool => ($row['kind'] ?? null) === 'provider'
        && ($row['action'] ?? null) === 'create'
        && ($row['replayed'] ?? false) === false
));
duo_check($snapshotReuse['exit'] !== 0
    && ($snapshotReuseDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && ($snapshotVerified['data']['manifest']['snapshot_hash'] ?? null)
        === $changedExport['snapshot_hash']
    && $changedExport['snapshot_hash'] !== $export['snapshot_hash']
    && $providerCreatesAfterSnapshotChange === $providerCreatesBeforeSnapshotChange,
    'same Git identities cannot reuse a Refresh candidate from another verified production snapshot');
$snapshotReuseReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$snapshotReuseReapReceipt = upc_last_json(
    $snapshotReuseReap['stdout'] . $snapshotReuseReap['stderr']
);
duo_check($snapshotReuseReap['exit'] === 0
    && ($snapshotReuseReapReceipt['disposition'] ?? null) === 'not-created',
    'snapshot-mismatch refusal is locally reapable without target destruction');

@unlink($trigger);
$environment['DUO_UPC_ORIGIN_FAIL'] = '1';
$preTargetArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/pre-target-refusal' : $argument,
    $createArgs
);
$preTarget = upc_process($preTargetArgs, $repo, $environment);
unset($environment['DUO_UPC_ORIGIN_FAIL']);
$beforeLocalReapTimeline = count(upc_rows($timeline));
$localReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$localReapReceipt = upc_last_json($localReap['stdout'] . $localReap['stderr']);
duo_check($preTarget['exit'] !== 0
    && $localReap['exit'] === 0
    && ($localReapReceipt['format'] ?? null) === 'duo-portable-preview-local-reap/v1'
    && ($localReapReceipt['disposition'] ?? null) === 'not-created'
    && count(upc_rows($timeline)) === $beforeLocalReapTimeline
    && upc_process(['git', 'show-ref', '--verify', '--quiet',
        'refs/heads/duo-preview/pre-target-refusal'], $repo)['exit'] === 1,
    'a pre-target origin refusal can be locally reaped without fabricating provider destruction');

@unlink($trigger);
upc_write($originCommitThreshold, "9\n");
$durableDriveArgs = array_map(
    static fn(string $argument): string => str_starts_with($argument, '--new-branch=')
        ? '--new-branch=duo-preview/durable-origin-drive' : $argument,
    $createArgs
);
$exhaustedDrive = upc_process($durableDriveArgs, $repo, $environment);
$exhaustedDriveDocument = upc_last_json(
    $exhaustedDrive['stdout'] . $exhaustedDrive['stderr']
);
$targetIndexPath = $repo . '/.git/duo-previews/targets/' . hash('sha256', 'preview') . '.json';
$targetIndex = json_decode((string) @file_get_contents($targetIndexPath), true);
$driveOperationId = is_array($targetIndex) ? ($targetIndex['operation_id'] ?? null) : null;
$driveEventPaths = is_string($driveOperationId)
    ? (glob($repo . '/.git/duo-previews/runs/' . $driveOperationId
        . '/events/*-origin-triggered.json') ?: [])
    : [];
$driveSequences = [];
foreach ($driveEventPaths as $driveEventPath) {
    $driveEvent = json_decode((string) @file_get_contents($driveEventPath), true);
    if (is_array($driveEvent) && is_int($driveEvent['data']['drive_sequence'] ?? null)) {
        $driveSequences[] = $driveEvent['data']['drive_sequence'];
    }
}
sort($driveSequences, SORT_NUMERIC);
duo_check($exhaustedDrive['exit'] !== 0
    && ($exhaustedDriveDocument['format'] ?? null) === 'duo-command-refusal/v1'
    && trim((string) @file_get_contents($trigger)) === '8'
    && $driveSequences === range(0, 7),
    'one public invocation durably records all eight bounded origin drive attempts');

$resumedDrive = upc_process($durableDriveArgs, $repo, $environment);
$resumedDriveReceipt = upc_last_json($resumedDrive['stdout'] . $resumedDrive['stderr']);
$resumedDriveEventPaths = is_string($driveOperationId)
    ? (glob($repo . '/.git/duo-previews/runs/' . $driveOperationId
        . '/events/*-origin-triggered.json') ?: [])
    : [];
$resumedDriveSequences = [];
foreach ($resumedDriveEventPaths as $driveEventPath) {
    $driveEvent = json_decode((string) @file_get_contents($driveEventPath), true);
    if (is_array($driveEvent) && is_int($driveEvent['data']['drive_sequence'] ?? null)) {
        $resumedDriveSequences[] = $driveEvent['data']['drive_sequence'];
    }
}
sort($resumedDriveSequences, SORT_NUMERIC);
duo_check($resumedDrive['exit'] === 0
    && ($resumedDriveReceipt['format'] ?? null) === 'duo-portable-preview-receipt/v1'
    && trim((string) @file_get_contents($trigger)) === '9'
    && $resumedDriveSequences === range(0, 8),
    'retrying the same public intent advances the durable drive sequence and commits on the ninth export');
$durableDriveReap = upc_process([
    PHP_BINARY, $duo, '--envs-file=' . $overlay,
    'preview', 'reap', 'preview', '--format=json',
], $repo, $environment);
$durableDriveReapReceipt = upc_last_json(
    $durableDriveReap['stdout'] . $durableDriveReap['stderr']
);
duo_check($durableDriveReap['exit'] === 0
    && ($durableDriveReapReceipt['disposition'] ?? null) === 'destroyed',
    'the preview completed after a durable origin-drive retry remains exactly reapable');
upc_write($originCommitThreshold, "1\n");

@touch($stop);
for ($attempt = 0; $attempt < 50; $attempt++) {
    $status = proc_get_status($endpoint);
    if (!$status['running']) break;
    usleep(20000);
}
if (is_resource($endpoint)) {
    @proc_terminate($endpoint);
    @proc_close($endpoint);
    $endpoint = null;
}

duo_check_summary('universal cloud preview through public duo preview');
