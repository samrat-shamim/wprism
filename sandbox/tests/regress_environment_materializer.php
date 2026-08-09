<?php
// DUO-3324: offline product orchestration and exact-reap regression.
declare(strict_types=1);

namespace Duo\Orchestrator {
    final class Refresh {
        public static function rebase(EnvironmentDriver $driver, string $production, string $branch, array $resolution = []): array {
            $root = trim((string) shell_exec('git rev-parse --show-toplevel'));
            $head = trim((string) shell_exec('git rev-parse HEAD'));
            exec('git update-ref ' . escapeshellarg('refs/heads/' . $branch) . ' ' . escapeshellarg($head), $out, $exit);
            if ($exit !== 0) throw new \RuntimeException('fixture could not create candidate ref');
            $path = $root . '/.git/materializer-plan-' . hash('sha256', $branch) . '.json';
            file_put_contents($path, json_encode([
                'context' => ['production_snapshot_hash' => hash('sha256', 'semantic-production')],
                'format' => 'duo-refresh-plan/v1',
                'plan_hash' => hash('sha256', 'semantic-plan'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return ['head' => $head, 'new_branch' => $branch, 'plan_path' => $path, 'run_id' => 'fixture'];
        }
    }

    final class CodeDeploy {
        public static function compile(EnvironmentDriver $driver, string $repo, string $artifact): array {
            return [
                'exit' => 0, 'stdout' => '', 'stderr' => '',
                'summary' => [
                    'artifact_hash' => hash('sha256', 'outer-release'),
                    'code' => ['code_revision' => hash('sha256', 'code-release')],
                    'revision_hash' => hash('sha256', 'state-release'),
                ],
            ];
        }
    }

    final class PlanSummary {
        public static function render(array $plan): array { return ['lines' => [], 'ok' => ($plan['clean'] ?? false) === true]; }
    }
}

namespace {
require_once __DIR__ . '/../../cli/src/EnvironmentDriver.php';
require_once __DIR__ . '/../../cli/src/EnvironmentLifecycle.php';

use Duo\Orchestrator\CommandEnvironmentProvider;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\EnvironmentLifecycleJournal;
use Duo\Orchestrator\EnvironmentMaterializer;

function em_fail(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function em_ok(bool $condition, string $message): void { if (!$condition) em_fail($message); echo "ok: $message\n"; }
function em_run(array $command, ?string $cwd = null): string {
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) em_fail('could not start fixture command');
    fclose($pipes[0]); $out = (string) stream_get_contents($pipes[1]); $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($proc);
    if ($exit !== 0) em_fail('fixture command failed: ' . implode(' ', $command) . "\n$err");
    return trim($out);
}
function em_remove(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') em_remove($path . '/' . $name);
    @rmdir($path);
}
/** @return list<string> */
function em_actions(string $log): array {
    if (!is_file($log)) return [];
    return array_map(static fn(string $line): string => (string) (json_decode($line, true)['action'] ?? ''), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}

final class MaterializerDriver implements EnvironmentDriver {
    public array $calls = [];
    public function __construct(private string $name, private string $repo, private string $productionCommit = '') {}
    public function name(): string { return $this->name; }
    public function driverId(): string { return 'fixture-' . $this->name; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'fixture'; }
    public function captureRaw(string $script): array {
        $this->calls[] = ['raw', $script];
        return str_contains($script, 'rev-parse --verify HEAD')
            ? ['exit' => 0, 'stdout' => $this->productionCommit . "\n", 'stderr' => '']
            : ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function captureWp(array $args): array {
        $this->calls[] = ['wp', $args];
        return ['exit' => 0, 'stdout' => json_encode(['clean' => true]) . "\n", 'stderr' => ''];
    }
    public function streamWp(array $args): int { $this->calls[] = ['stream', $args]; return 0; }
    public function wpInstruction(array $args): string { return 'fixture'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver($this->name, $this->driverId(), $operation, [
            DriverCapability::ATTACH => true,
            DriverCapability::CODE_MATERIALIZE => true,
            DriverCapability::DB_SNAPSHOT_CREATE => true,
            DriverCapability::DB_SNAPSHOT_RESTORE => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::WP_CONTROL => true,
        ]);
    }
}

$tmp = sys_get_temp_dir() . '/duo-environment-materializer-' . bin2hex(random_bytes(7));
$repo = $tmp . '/repo';
mkdir($repo, 0700, true);
try {
    em_run(['git', 'init', '-b', 'feature'], $repo);
    em_run(['git', 'config', 'user.email', 'test@example.invalid'], $repo);
    em_run(['git', 'config', 'user.name', 'Materializer Test'], $repo);
    file_put_contents($repo . '/tracked.txt', "branch\n");
    em_run(['git', 'add', 'tracked.txt'], $repo);
    em_run(['git', 'commit', '-m', 'feature'], $repo);
    $commit = em_run(['git', 'rev-parse', 'HEAD'], $repo);

    $providerScript = $tmp . '/provider.php';
    file_put_contents($providerScript, <<<'PHP'
<?php
declare(strict_types=1);
$mode = $argv[1]; $log = $argv[2]; $state = $argv[3];
$raw = (string) stream_get_contents(STDIN); $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
file_put_contents($log, $raw, FILE_APPEND | LOCK_EX);
function c(mixed $v): string { if (is_array($v)) { if (!array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as $k => $x) $v[$k] = json_decode(c($x), true); } return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
$h = static fn(string $v): string => hash('sha256', $v);
$fixtureState = is_file($state) ? trim((string) file_get_contents($state)) : 'current';
$stale = $fixtureState === 'stale';
$ttlStale = $fixtureState === 'ttl-stale';
$mutationStale = $fixtureState === 'mutation-stale';
$identity = [
  'environment_identity' => 'environment-identity-0001', 'lease_generation' => $stale ? 4 : 3,
  'lease_id' => $stale ? 'lease-identity-stale-0001' : 'lease-identity-0001',
  'ownership_receipt_sha256' => $h('owner'), 'resource_id' => 'resource-identity-0001',
  'url' => 'https://branch.example.test',
];
$a = $request['action']; $i = $request['input'] ?? [];
$caps = ['environment.attach','environment.create','environment.destroy','environment.detach','environment.inspect','environment.mutation.acquire','environment.mutation.read','environment.mutation.release','environment.ttl','environment.ttl.read','environment.url.discover','environment.url.set','operation.receipts','repository.materialize','snapshot.set.abort','snapshot.set.create','snapshot.set.prepare','snapshot.set.read','snapshot.set.restore'];
if ($mode === 'attach-only') $caps = array_values(array_diff($caps, ['environment.create', 'environment.destroy']));
$result = match ($a) {
 'capabilities' => ['capabilities' => $caps],
 'inspect','attach','create' => $identity + ['presence' => 'present'],
 'snapshot-prepare' => ['lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'snapshot_session_id'=>(string)$i['snapshot_session_id'],'source_identity'=>'environment-identity-0001'],
 'snapshot-create' => ['database_sha256'=>$h('db'),'lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'media_sha256'=>$h('media'),'retention_receipt_sha256'=>$h('retention'),'semantic_snapshot_sha256'=>$mode === 'mismatch' ? $h('wrong') : (string)$i['expected_semantic_snapshot_sha256'],'snapshot_session_id'=>(string)$i['snapshot_session_id'],'snapshot_set_id'=>'snapshot-set-0001','snapshot_receipt_sha256'=>$h('snapshot-receipt'),'source_identity'=>'environment-identity-0001'],
 'snapshot-read' => ['database_sha256'=>$h('db'),'immutable'=>true,'lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'media_sha256'=>$h('media'),'retention_receipt_sha256'=>$h('retention'),'semantic_snapshot_sha256'=>(string)$i['expected_semantic_snapshot_sha256'],'snapshot_session_id'=>(string)$i['snapshot_session_id'],'snapshot_set_id'=>(string)$i['snapshot_set_id'],'snapshot_receipt_sha256'=>$h('snapshot-receipt'),'source_identity'=>'environment-identity-0001'],
 'snapshot-abort' => ['disposition'=>'aborted','lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'snapshot_session_id'=>(string)$i['snapshot_session_id'],'source_identity'=>'environment-identity-0001'],
 'mutation-acquire' => $identity + ['mutation_generation'=>1,'mutation_id'=>'mutation-lease-0001','mutation_owner'=>(string)$request['operation_id'],'mutation_receipt_sha256'=>$h('mutation-held'),'state'=>'held'],
 'mutation-read' => $identity + ['mutation_generation'=>$mutationStale ? 2 : 1,'mutation_id'=>$mutationStale ? 'mutation-lease-stale-0001' : 'mutation-lease-0001','mutation_owner'=>(string)$request['operation_id'],'mutation_receipt_sha256'=>$h($mutationStale ? 'mutation-stale' : 'mutation-released'),'state'=>'released'],
 'mutation-release' => $identity + ['mutation_generation'=>1,'mutation_id'=>'mutation-lease-0001','mutation_owner'=>(string)$request['operation_id'],'mutation_receipt_sha256'=>$h('mutation-released'),'state'=>'released'],
 'snapshot-restore' => $identity + ['snapshot_set_id'=>(string)$i['snapshot_set_id']],
 'repository-materialize' => $identity + ['branch_commit'=>(string)$i['branch_commit'],'repository_receipt_sha256'=>$h('repo')],
 'url-set' => $identity,
 'ttl-set' => $identity + ['expires_at'=>'2030-01-02T04:04:05Z','ttl_generation'=>1,'ttl_lease_id'=>'ttl-lease-identity-0001','ttl_receipt_sha256'=>$h('ttl'),'ttl_state'=>'active'],
 'ttl-read' => $identity + ['expires_at'=>'2030-01-02T04:04:05Z','ttl_generation'=>$ttlStale ? 2 : 1,'ttl_lease_id'=>$ttlStale ? 'ttl-lease-stale-0001' : 'ttl-lease-identity-0001','ttl_receipt_sha256'=>$h($ttlStale ? 'ttl-stale' : 'ttl'),'ttl_state'=>'active'],
 'destroy','detach' => ['absence_proof_sha256'=>$h('absence'),'disposition'=>$a === 'destroy' ? 'destroyed' : 'detached','environment_identity'=>'environment-identity-0001','lease_generation'=>3,'lease_id'=>'lease-identity-0001','ownership_receipt_sha256'=>$h('owner'),'resource_id'=>'resource-identity-0001'],
 default => [],
};
$response=['action'=>$a,'environment'=>$request['environment'],'format'=>'duo-branch-environment-provider-response/v1','operation_id'=>$request['operation_id'],'provider'=>['id'=>'fixture-provider','protocol'=>1],'result'=>$result,'status'=>'ok'];
echo c($response)."\n";
PHP);
    $state = $tmp . '/provider-state';
    $sourceLog = $tmp . '/source.log';
    $targetLog = $tmp . '/target.log';
    $cfg = static fn(string $mode, string $log): array => [
        '_machine_local' => true,
        'environment_provider' => [
            'command' => [PHP_BINARY, $providerScript, $mode, $log, $state], 'timeout_seconds' => 5,
        ],
    ];
    $source = new MaterializerDriver('production', '/production/repo', $commit);
    $target = new MaterializerDriver('branch', '/branch/repo');
    $journal = new EnvironmentLifecycleJournal($repo . '/.git/duo-environments');
    $promotions = 0;
    $promotionCalls = [];
    $promote = static function (EnvironmentDriver $driver, string $artifactPath, array $summary, string $owner) use (&$promotions, &$promotionCalls): array {
        $promotions++;
        $operationId = str_starts_with($owner, 'materialize-') ? substr($owner, strlen('materialize-')) : $owner;
        $body = [
            'artifact_hash' => (string) $summary['artifact_hash'],
            'checkpoint_identity' => 'checkpoint-identity-0001',
            'code_revision' => isset($summary['code']['code_revision']) ? (string) $summary['code']['code_revision'] : null,
            'format' => 'duo-branch-environment-promotion-receipt/v1',
            'operation_id' => $operationId,
            'owner' => $owner,
            'state_revision' => (string) $summary['revision_hash'],
            'status' => 'completed',
        ];
        $body['receipt_sha256'] = hash('sha256', \Duo\Orchestrator\EnvironmentLifecycleCanon::encode($body));
        $promotionCalls[] = ['artifact_path' => $artifactPath, 'owner' => $owner, 'receipt' => $body];
        return $body;
    };

    $old = getcwd(); chdir($repo);
    $badSource = CommandEnvironmentProvider::fromEnvironment('production', $cfg('mismatch', $sourceLog));
    $targetProvider = CommandEnvironmentProvider::fromEnvironment('branch', $cfg('ok', $targetLog));
    try {
        EnvironmentMaterializer::materialize($source, $target, $badSource, $targetProvider, $journal, [
            'branch' => 'feature', 'create' => false, 'ttl_seconds' => 3600,
        ], $promote);
        em_fail('incoherent physical snapshot was accepted');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'not coherent'), 'semantic P and physical DB/media snapshot must share one identity');
    }
    em_ok(em_actions($targetLog) === ['capabilities'], 'composite preflight precedes snapshot and incoherence makes no target mutation');
    em_ok(in_array('snapshot-abort', em_actions($sourceLog), true), 'incoherent snapshot aborts the exact prepared source session');
    em_ok($promotions === 0, 'failed snapshot coherence cannot enter code/state promotion');

    $goodSource = CommandEnvironmentProvider::fromEnvironment('production', $cfg('ok', $sourceLog));
    $receipt = EnvironmentMaterializer::materialize($source, $target, $goodSource, $targetProvider, $journal, [
        'branch' => 'feature', 'create' => false, 'ttl_seconds' => 3600,
    ], $promote);
    em_ok(($receipt['mode'] ?? null) === 'attach' && ($receipt['expires_at'] ?? null) === '2030-01-02T04:04:05Z', 'attach materialization publishes observable provider TTL');
    em_ok(($receipt['code_revision'] ?? null) === hash('sha256', 'code-release')
        && ($receipt['state_revision'] ?? null) === hash('sha256', 'state-release')
        && $receipt['code_revision'] !== $receipt['state_revision'], 'release receipt preserves separate code and state identities');
    em_ok($promotions === 1, 'materialization uses the supplied existing promotion path exactly once');
    em_ok(($promotionCalls[0]['receipt']['artifact_hash'] ?? null) === ($receipt['outer_artifact_hash'] ?? null)
        && ($promotionCalls[0]['receipt']['owner'] ?? null) === 'materialize-' . $receipt['operation_id'],
        'promotion consumes the exact frozen artifact under the deterministic operation owner');
    $targetActions = em_actions($targetLog);
    $expectedTail = ['capabilities','attach','mutation-acquire','snapshot-restore','repository-materialize','url-set','inspect','ttl-set','ttl-read','mutation-release'];
    em_ok(array_slice($targetActions, -count($expectedTail)) === $expectedTail, 'target phases remain under one fence through restore, repository, URL, convergence, TTL readback, and release');

    $beforeResume = [count(em_actions($sourceLog)), count(em_actions($targetLog)), $promotions];
    $same = EnvironmentMaterializer::materialize($source, $target, $goodSource, $targetProvider, $journal, [
        'branch' => 'feature', 'create' => false, 'ttl_seconds' => 3600,
    ], $promote);
    em_ok(($same['resumed'] ?? false) === true
        && $beforeResume === [count(em_actions($sourceLog)), count(em_actions($targetLog)), $promotions], 'completed retry is idempotent with zero provider or promotion calls');

    file_put_contents($state, "ttl-stale\n");
    try {
        EnvironmentMaterializer::reap($target, $targetProvider, $journal);
        em_fail('changed TTL lease reaped an environment');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'ttl_') || str_contains(strtolower($e->getMessage()), 'ttl '), 'changed TTL generation/id refuses stale reap');
    }
    em_ok(!in_array('detach', em_actions($targetLog), true), 'changed TTL refusal makes no detach/destroy call');
    file_put_contents($state, "stale\n");
    try {
        EnvironmentMaterializer::reap($target, $targetProvider, $journal);
        em_fail('stale lease reaped a reused environment');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'lease_') || str_contains($e->getMessage(), 'lease '), 'changed provider lease refuses stale reap');
    }
    em_ok(!in_array('detach', em_actions($targetLog), true), 'stale lease refusal makes no detach/destroy call');
    file_put_contents($state, "mutation-stale\n");
    try {
        EnvironmentMaterializer::reap($target, $targetProvider, $journal);
        em_fail('changed mutation fence reaped an environment');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'mutation_') || str_contains(strtolower($e->getMessage()), 'mutation '), 'changed mutation fence refuses stale reap');
    }
    em_ok(!in_array('detach', em_actions($targetLog), true), 'changed mutation fence refusal makes no detach/destroy call');
    file_put_contents($state, "current\n");
    $reap = EnvironmentMaterializer::reap($target, $targetProvider, $journal);
    em_ok(($reap['disposition'] ?? null) === 'detached' && !in_array('destroy', em_actions($targetLog), true), 'attached target reaps only through explicit detach');
    $reapAgain = EnvironmentMaterializer::reap($target, $targetProvider, $journal);
    em_ok(($reapAgain['resumed'] ?? false) === true, 'repeated reap is idempotent from absence evidence');

    $createLog = $tmp . '/create.log';
    $createdDriver = new MaterializerDriver('branch-created', '/created/repo');
    $createdProvider = CommandEnvironmentProvider::fromEnvironment('branch-created', $cfg('ok', $createLog));
    $created = EnvironmentMaterializer::materialize($source, $createdDriver, $goodSource, $createdProvider, $journal, [
        'branch' => 'feature', 'create' => true, 'ttl_seconds' => 0,
    ], $promote);
    em_ok(($created['mode'] ?? null) === 'create' && array_key_exists('expires_at', $created) && $created['expires_at'] === null
        && in_array('create', em_actions($createLog), true) && !in_array('attach', em_actions($createLog), true),
        'explicit create never falls back to attach and omits unrequested TTL');
    $destroyed = EnvironmentMaterializer::reap($createdDriver, $createdProvider, $journal);
    em_ok(($destroyed['disposition'] ?? null) === 'destroyed'
        && in_array('destroy', em_actions($createLog), true) && !in_array('detach', em_actions($createLog), true),
        'created target reaps only through exact provider destroy');

    $blockedLog = $tmp . '/blocked.log';
    $blockedDriver = new MaterializerDriver('branch-blocked', '/blocked/repo');
    $blockedProvider = CommandEnvironmentProvider::fromEnvironment('branch-blocked', $cfg('attach-only', $blockedLog));
    $sourceBeforeBlocked = count(em_actions($sourceLog));
    try {
        EnvironmentMaterializer::materialize($source, $blockedDriver, $goodSource, $blockedProvider, $journal, [
            'branch' => 'feature', 'create' => true, 'ttl_seconds' => 0,
        ], $promote);
        em_fail('create was emulated by an attach-only provider');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'environment.create') && str_contains($e->getMessage(), 'environment.destroy'),
            'create requires explicit create plus recoverable destroy capability');
    }
    em_ok(array_slice(em_actions($sourceLog), $sourceBeforeBlocked) === ['capabilities']
        && em_actions($blockedLog) === ['capabilities'], 'unsupported create refuses both sides before snapshot or target mutation');

    chdir($old);
    echo "PASS: environment materializer orchestration regression\n";
} finally {
    if (isset($old) && getcwd() !== $old) chdir($old);
    em_remove($tmp);
}
}
