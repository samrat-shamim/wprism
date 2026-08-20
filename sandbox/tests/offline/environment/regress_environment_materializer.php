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

    /**
     * The renderer's own verdict is not what this suite exercises; only that
     * a converged plan is clean and a drifted one is not. PlanContract below
     * is deliberately NOT stubbed — the complete-envelope check at the
     * convergence boundary is product behavior under test (DUO-3384).
     */
    final class PlanSummary {
        public static function render(array $plan): array {
            return ['lines' => [], 'ok' => ($plan['drift'] ?? []) === [] && ($plan['conflict'] ?? []) === []];
        }
    }
}

namespace {
require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanContract.php';
require_once __DIR__ . '/../../../../cli/src/Environment/EnvironmentLifecycle.php';
require_once __DIR__ . '/../../../../cli/src/Environment/PortablePreviewMaterializer.php';

use Duo\Orchestrator\CommandEnvironmentProvider;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\EnvironmentLifecycleJournal;
use Duo\Orchestrator\EnvironmentLifecycleCanon;
use Duo\Orchestrator\EnvironmentMaterializer;
use Duo\Orchestrator\PortablePreviewMaterializer;
use Duo\Orchestrator\ProviderLeaseBoundEnvironmentDriver;

function em_fail(string $message): never { fwrite(STDERR, "FAIL: $message\n");
exit(1); }
function em_ok(bool $condition, string $message): void { if (!$condition) em_fail($message);
echo "ok: $message\n"; }
function em_run(array $command, ?string $cwd = null): string {
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) em_fail('could not start fixture command');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) em_fail('fixture command failed: ' . implode(' ', $command) . "\n$err");
    return trim($out);
}
function em_remove(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path);
    return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') em_remove($path . '/' . $name);
    @rmdir($path);
}
/** @return list<string> */
function em_actions(string $log): array {
    if (!is_file($log)) return [];
    return array_map(static fn(string $line): string => (string) (json_decode($line, true)['action'] ?? ''), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}

/**
 * One complete `wp duo plan --format=json` envelope, spelled out the way
 * agent/src/Apply/Apply.php emits it. Branch convergence refuses anything less.
 *
 * @param array<string,list<mixed>> $overrides
 * @return array<string,list<mixed>>
 */
function em_plan(array $overrides = []): array {
    return $overrides + [
        'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
        'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
        'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
        'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
        'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
        'skipped_user_meta' => [],
        'unchanged' => [], 'update' => [], 'uploads_inventory' => [], 'warnings' => [],
    ];
}

final class MaterializerDriver implements EnvironmentDriver {
    public array $calls = [];
    /** Raw `wp duo plan --format=json` stdout; a complete clean envelope by default. */
    public string $planJson = '';
    public function __construct(private string $name, private string $repo, private string $productionCommit = '') {
        $this->planJson = (string) json_encode(em_plan(), JSON_UNESCAPED_SLASHES);
    }
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
        // The materializer reads the source URL binding (home + uploads) to
        // rebind a rehearsal target off its restored snapshot; every other
        // wp call in this fixture is `duo plan`.
        if (($args[0] ?? null) === 'eval' && str_contains((string) ($args[1] ?? ''), 'get_option')) {
            return ['exit' => 0, 'stdout' => "http://source.example:9600\nhttp://source.example:9600/wp-content/uploads\n", 'stderr' => ''];
        }
        return ['exit' => 0, 'stdout' => $this->planJson . "\n", 'stderr' => ''];
    }
    public function streamWp(array $args): int { $this->calls[] = ['stream', $args];
    return 0; }
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

/** A cloud target is unreachable until an exact provider lease/fence is bound. */
final class PortableMaterializerDriver implements ProviderLeaseBoundEnvironmentDriver {
    /** @var list<array{kind:string,payload:mixed,phase:string}> */
    public array $calls = [];
    /** @var list<array{operation_id:string,identity:array<string,mixed>,fence:array<string,mixed>}> */
    public array $bindings = [];
    public int $releases = 0;
    private bool $bound = false;
    private string $phase = '';

    public function __construct(private string $name, private string $repo) {}
    public function name(): string { return $this->name; }
    public function driverId(): string { return 'fixture-cloud-preview'; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'fixture cloud preview'; }
    public function captureRaw(string $script): array {
        $this->assertBound();
        $this->calls[] = ['kind' => 'raw', 'payload' => $script, 'phase' => $this->phase];
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function captureWp(array $args): array {
        $this->assertBound();
        $this->calls[] = ['kind' => 'wp', 'payload' => $args, 'phase' => $this->phase];
        return ['exit' => 0, 'stdout' => "{}\n", 'stderr' => ''];
    }
    public function streamWp(array $args): int {
        $this->assertBound();
        $this->calls[] = ['kind' => 'stream', 'payload' => $args, 'phase' => $this->phase];
        return 0;
    }
    public function wpInstruction(array $args): string { return 'fixture cloud preview'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver($this->name, $this->driverId(), $operation, [
            DriverCapability::CODE_MATERIALIZE => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::WP_CONTROL => true,
        ]);
    }
    public function bindProviderLease(string $operationId, array $identity, array $mutationFence): void {
        if (($mutationFence['state'] ?? null) !== 'held') throw new \RuntimeException('fixture received a released fence');
        $this->bindings[] = ['operation_id' => $operationId, 'identity' => $identity, 'fence' => $mutationFence];
        $this->bound = true;
    }
    public function beginProviderCommandPhase(string $phase): void { $this->phase = $phase; }
    public function releaseProviderLease(string $operationId, array $releasedFence): void {
        if (($releasedFence['state'] ?? null) !== 'released') throw new \RuntimeException('fixture received a held release');
        $this->bound = false;
        $this->releases++;
    }
    private function assertBound(): void {
        if (!$this->bound) throw new \RuntimeException('cloud target command ran without a held provider lease/fence');
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
$requests = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [];
$releaseSeen = false;
foreach ($requests as $line) {
  $prior = json_decode($line, true);
  if (($prior['action'] ?? null) === 'mutation-release') $releaseSeen = true;
}
$identity = [
  'environment_identity' => 'environment-identity-0001', 'lease_generation' => $stale ? 4 : 3,
  'lease_id' => $stale ? 'lease-identity-stale-0001' : 'lease-identity-0001',
  'ownership_receipt_sha256' => $h('owner'), 'resource_id' => 'resource-identity-0001',
  'url' => 'https://branch.example.test',
];
$a = $request['action']; $i = $request['input'] ?? [];
if ($a === 'snapshot-create' && $mode === 'create-loss') {
  $lossMarker = $state . '.snapshot-create-loss';
  if (!is_file($lossMarker)) {
    file_put_contents($lossMarker, "lost\n", LOCK_EX);
    exit(75); // provider applied/accepted create but its response was lost
  }
}
$caps = ['environment.attach','environment.create','environment.destroy','environment.detach','environment.inspect','environment.mutation.acquire','environment.mutation.read','environment.mutation.release','environment.ttl','environment.ttl.read','environment.url.discover','environment.url.set','operation.receipts','repository.materialize','repository.sync','snapshot.set.abort','snapshot.set.create','snapshot.set.prepare','snapshot.set.read','snapshot.set.restore'];
if ($mode === 'attach-only') $caps = array_values(array_diff($caps, ['environment.create', 'environment.destroy']));
$owner = (string) ($i['mutation_owner'] ?? $i['expected_mutation_owner'] ?? '');
$materialFence = str_contains($owner, 'duo-env-materialize-');
$fenceId = $materialFence ? 'mutation-material-0001' : 'mutation-reap-0001';
$heldReceipt = $h($materialFence ? 'mutation-material-held' : 'mutation-reap-held');
$releasedReceipt = $h($materialFence ? 'mutation-material-released' : 'mutation-reap-released');
$mutation = static fn(string $state, string $receipt): array => $identity + [
  'mutation_generation'=>1, 'mutation_id'=>$fenceId, 'mutation_owner'=>$owner,
  'mutation_receipt_sha256'=>$receipt, 'state'=>$state,
];
$result = match ($a) {
 'capabilities' => ['capabilities' => $caps],
 'inspect','attach','create' => $identity + ['presence' => 'present'],
 'snapshot-prepare' => ['lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'snapshot_session_id'=>(string)$i['snapshot_session_id'],'source_identity'=>'environment-identity-0001'],
 'snapshot-create' => ['database_sha256'=>$h('db'),'lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'media_sha256'=>$h('media'),'retention_receipt_sha256'=>$h('retention'),'semantic_snapshot_sha256'=>$mode === 'mismatch' ? $h('wrong') : (string)$i['expected_semantic_snapshot_sha256'],'snapshot_session_id'=>(string)$i['expected_snapshot_session_id'],'snapshot_set_id'=>'snapshot-set-0001','snapshot_set_receipt_sha256'=>$h('snapshot-set'),'source_identity'=>'environment-identity-0001'],
 'snapshot-read' => ['database_sha256'=>$h('db'),'immutable'=>true,'lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'media_sha256'=>$h('media'),'retention_receipt_sha256'=>$h('retention'),'semantic_snapshot_sha256'=>$h('semantic-production'),'snapshot_session_id'=>(string)$i['expected_snapshot_session_id'],'snapshot_set_id'=>(string)$i['expected_snapshot_set_id'],'snapshot_set_receipt_sha256'=>(string)$i['expected_snapshot_set_receipt_sha256'],'source_identity'=>'environment-identity-0001'],
 'snapshot-abort' => ['disposition'=>'aborted','lease_generation'=>(int)$i['expected_source_lease_generation'],'lease_id'=>(string)$i['expected_source_lease_id'],'lease_receipt_sha256'=>(string)$i['expected_source_lease_receipt_sha256'],'snapshot_session_id'=>(string)$i['expected_snapshot_session_id'],'source_identity'=>(string)$i['expected_source_identity']],
 'snapshot-restore' => $identity + ['snapshot_set_id'=>(string)$i['snapshot_set_id']],
 'repository-sync' => $identity + ['branch_commit'=>(string)$i['branch_commit'],'branch_ref'=>(string)$i['branch_ref'],'candidate_publication_receipt_sha256'=>(string)$i['candidate_publication_receipt_sha256'],'repository_authority_sha256'=>(string)$i['repository_authority_sha256'],'repository_sync_receipt_sha256'=>$h('repository-sync-' . (string)$i['branch_ref'])],
 'repository-materialize' => $identity + ['branch_commit'=>(string)$i['branch_commit'],'repository_receipt_sha256'=>$h('repo')],
 'url-set' => $identity,
 'mutation-acquire' => $mutation('held', $heldReceipt),
 'mutation-read' => $mutationStale
   ? $identity + ['mutation_generation'=>2,'mutation_id'=>'mutation-stale-0001','mutation_owner'=>$owner,'mutation_receipt_sha256'=>$h('mutation-stale'),'state'=>$materialFence && $releaseSeen ? 'released' : 'held']
   : $mutation($materialFence && $releaseSeen ? 'released' : 'held', $materialFence && $releaseSeen ? $releasedReceipt : $heldReceipt),
 'mutation-release' => $mutation('released', $releasedReceipt),
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
    $promote = static function (EnvironmentDriver $driver, array $frozenContext) use (&$promotions, &$promotionCalls): array {
        $promotions++;
        $summary = $frozenContext['compiled_summary'];
        $body = [
            'artifact_hash' => (string) $summary['artifact_hash'],
            'checkpoint_identity' => hash('sha256', 'checkpoint-' . $frozenContext['operation_id']),
            'code_revision' => isset($summary['code']['code_revision']) ? (string) $summary['code']['code_revision'] : null,
            'format' => 'duo-branch-environment-promotion-receipt/v1',
            'operation_id' => $frozenContext['operation_id'],
            'owner' => $frozenContext['promotion_owner'],
            'state_revision' => (string) $summary['revision_hash'],
            'status' => 'completed',
        ];
        $body['receipt_sha256'] = hash('sha256', \Duo\Orchestrator\EnvironmentLifecycleCanon::encode($body));
        $promotionCalls[] = ['context' => $frozenContext, 'receipt' => $body];
        return $body;
    };

    $old = getcwd();
    chdir($repo);
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
    em_ok($promotions === 0, 'failed snapshot coherence cannot enter code/state promotion');
    em_ok(in_array('snapshot-abort', em_actions($sourceLog), true), 'received incoherent snapshot evidence aborts its prepared source session');
    $badReap = EnvironmentMaterializer::reap($target, $targetProvider, $journal, $badSource);
    em_ok(($badReap['disposition'] ?? null) === 'aborted-no-target', 'invalid pre-target snapshot operation can be explicitly reaped before retry');

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
        && ($promotionCalls[0]['receipt']['owner'] ?? null) === 'duo-env-promotion-' . $receipt['operation_id']
        && ($promotionCalls[0]['context']['artifact_path'] ?? null) !== '',
        'promotion consumes the exact frozen artifact under the deterministic operation owner');
    $targetActions = em_actions($targetLog);
    $expectedTail = ['capabilities','attach','mutation-acquire','mutation-read','snapshot-restore','repository-materialize','url-set','inspect','ttl-set','ttl-read','mutation-release'];
    em_ok(array_slice($targetActions, -count($expectedTail)) === $expectedTail, 'target phases retain a held fence through restore/repository/URL/TTL, then release it');

    $beforeResume = [count(em_actions($sourceLog)), count(em_actions($targetLog)), $promotions];
    $same = EnvironmentMaterializer::materialize($source, $target, $goodSource, $targetProvider, $journal, [
        'branch' => 'feature', 'create' => false, 'ttl_seconds' => 3600,
    ], $promote);
    em_ok(($same['resumed'] ?? false) === true
        && $beforeResume === [count(em_actions($sourceLog)), count(em_actions($targetLog)), $promotions], 'completed retry is idempotent with zero provider or promotion calls');

    // A create request may have reached the source provider while its response
    // was lost. Automatic catch cleanup must preserve the deterministic session
    // for retry, rather than aborting a possibly completed snapshot set.
    $lossSourceLog = $tmp . '/create-loss-source.log';
    $lossTargetLog = $tmp . '/create-loss-target.log';
    $lossDriver = new MaterializerDriver('branch-create-loss', '/create-loss/repo');
    $lossSource = CommandEnvironmentProvider::fromEnvironment('production', $cfg('create-loss', $lossSourceLog));
    $lossTarget = CommandEnvironmentProvider::fromEnvironment('branch-create-loss', $cfg('ok', $lossTargetLog));
    try {
        EnvironmentMaterializer::materialize($source, $lossDriver, $lossSource, $lossTarget, $journal, [
            'branch' => 'feature', 'create' => false, 'ttl_seconds' => 0,
        ], $promote);
        em_fail('lost snapshot-create response was accepted without retry');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'provider failed'), 'lost snapshot-create response stops without inventing completion evidence');
    }
    em_ok(!in_array('snapshot-abort', em_actions($lossSourceLog), true)
        && em_actions($lossTargetLog) === ['capabilities'], 'automatic failure after snapshot-create intent preserves source session and makes no target mutation');
    $lossRetrySource = CommandEnvironmentProvider::fromEnvironment('production', $cfg('ok', $lossSourceLog));
    $lossReceipt = EnvironmentMaterializer::materialize($source, $lossDriver, $lossRetrySource, $lossTarget, $journal, [
        'branch' => 'feature', 'create' => false, 'ttl_seconds' => 0,
    ], $promote);
    $lossCreates = array_values(array_filter(em_actions($lossSourceLog), static fn(string $action): bool => $action === 'snapshot-create'));
    em_ok(($lossReceipt['mode'] ?? null) === 'attach' && count($lossCreates) === 2
        && !in_array('snapshot-abort', em_actions($lossSourceLog), true), 'retry reissues the exact snapshot-create operation without aborting its deterministic session');
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

    // DUO-3384: branch convergence trusts PlanSummary::render(...)['ok'], and
    // the renderer intentionally tolerates partial fixtures — `{}` renders
    // clean. Convergence must therefore validate the complete agent envelope
    // first, and must never journal a convergence it did not observe. The
    // same target is driven through all three plans in order, so a recorded
    // success would be visible as a resumed release-converged event.
    $contractLog = $tmp . '/plan-contract.log';
    $contractDriver = new MaterializerDriver('branch-plan-contract', '/plan-contract/repo');
    $contractProvider = CommandEnvironmentProvider::fromEnvironment('branch-plan-contract', $cfg('ok', $contractLog));
    $contractOptions = ['branch' => 'feature', 'create' => false, 'ttl_seconds' => 0];
    $incomplete = [
        '{}' => 'an empty plan object',
        (string) json_encode(array_diff_key(em_plan(), ['conflict' => null]), JSON_UNESCAPED_SLASHES)
            => 'a plan missing one required bucket',
    ];
    $contractPromotionBase = $promotions;
    foreach ($incomplete as $payload => $label) {
        $contractDriver->planJson = $payload;
        try {
            EnvironmentMaterializer::materialize(
                $source, $contractDriver, $goodSource, $contractProvider, $journal, $contractOptions, $promote
            );
            em_fail("$label was accepted as branch convergence");
        } catch (Throwable $e) {
            em_ok(str_contains($e->getMessage(), 'branch environment convergence')
                && str_contains($e->getMessage(), 'incomplete agent plan envelope'),
                "$label refuses branch convergence with a contract diagnostic");
        }
        $events = $journal->latestForTarget('branch-plan-contract')['events'] ?? [];
        em_ok(!in_array('release-converged', array_column($events, 'event'), true),
            "$label never records a converged branch environment");
        em_ok($promotions === $contractPromotionBase + 1,
            "$label refuses without replaying the journaled promotion");
    }
    $contractDriver->planJson = (string) json_encode(em_plan(), JSON_UNESCAPED_SLASHES);
    $contractReceipt = EnvironmentMaterializer::materialize(
        $source, $contractDriver, $goodSource, $contractProvider, $journal, $contractOptions, $promote
    );
    $contractEvents = array_column($journal->latestForTarget('branch-plan-contract')['events'] ?? [], 'event');
    em_ok(($contractReceipt['mode'] ?? null) === 'attach' && in_array('release-converged', $contractEvents, true)
        && $promotions === $contractPromotionBase + 1,
        'the same target converges once its plan envelope is complete, on the one journaled promotion');

    // Universal cloud preview consumes the committed portable export on a
    // reviewed clean base. It must not counterfeit the physical DB/media
    // snapshot phases or receipt used by production-fidelity rehearsal.
    em_run(['git', 'branch', 'portable-candidate', $commit], $repo);
    file_put_contents($repo . '/tracked.txt', "operator checkout remains here\n");
    em_run(['git', 'add', 'tracked.txt'], $repo);
    em_run(['git', 'commit', '-m', 'operator checkout after isolated candidate'], $repo);
    $operatorBranch = em_run(['git', 'symbolic-ref', '--short', 'HEAD'], $repo);
    $operatorCommit = em_run(['git', 'rev-parse', 'HEAD'], $repo);
    $portableLog = $tmp . '/portable.log';
    $portableDriver = new PortableMaterializerDriver('branch-portable', '/portable/repo');
    $portableProvider = CommandEnvironmentProvider::fromEnvironment(
        'branch-portable',
        $cfg('ok', $portableLog)
    );
    $portableExport = [
        'artifact_hash' => hash('sha256', 'portable-artifact'),
        'chunks' => [[
            'index' => 0, 'offset' => 0, 'sha256' => hash('sha256', 'portable-chunk'), 'size' => 32,
        ]],
        'code_revision' => hash('sha256', 'portable-production-code'),
        'expected_production_commit' => $commit,
        'export_sha256' => hash('sha256', 'portable-export'),
        'export_size' => 32,
        'format' => 'duo-cloud-origin-export-manifest/v1',
        'generation' => 7,
        'repository_revision_hash' => hash('sha256', 'portable-production-state'),
        'snapshot_hash' => hash('sha256', 'portable-snapshot'),
    ];
    $portableExport['manifest_sha256'] = hash(
        'sha256',
        \Duo\Canon::encode($portableExport)
    );
    $reviewedBase = [
        'format' => 'duo-reviewed-preview-base/v1',
        'image_digest' => 'sha256:' . hash('sha256', 'reviewed-wordpress-base'),
        'platform_fingerprint_sha256' => hash('sha256', 'php-wordpress-platform'),
        'review_receipt_sha256' => hash('sha256', 'reviewed-clean-base-receipt'),
    ];
    $containmentBasis = [
        'egress_evidence' => 'host-nft-input-forward-default-deny-readback/v1',
        'format' => 'duo-reviewed-preview-base-containment/v1',
        'image_reference' => 'registry.example.test/duo/wordpress@' . $reviewedBase['image_digest'],
        'reviewed_base' => $reviewedBase,
        'routing_evidence' => 'credential-free-route-authority-readback/v1',
        'runtime_configuration_sha256' => hash('sha256', 'portable-runtime-configuration'),
        'seccomp_profile_sha256' => hash('sha256', 'portable-seccomp-profile'),
        'secrets_evidence' => 'generation-private-files-readonly-mount-readback/v1',
        'storage_evidence' => 'dm-crypt-xfs-project-quota-exact-readback/v1',
    ];
    $reviewedBaseContainment = ['descriptor_sha256' => hash(
        'sha256',
        "duo-reviewed-preview-base-containment/v1\0"
            . EnvironmentLifecycleCanon::encode($containmentBasis)
    )] + $containmentBasis;
    $repositoryAuthorityBasis = [
        'credential_helper_sha256' => hash('sha256', 'portable credential helper'),
        'format' => 'duo-cloud-repository-authority/v1',
        'ref_prefix' => 'refs/heads/duo-preview/',
        'remote_url_sha256' => hash('sha256', 'portable remote URL'),
    ];
    $repositoryAuthority = ['descriptor_sha256' => hash(
        'sha256',
        "duo-cloud-repository-authority/v1\0"
            . EnvironmentLifecycleCanon::encode($repositoryAuthorityBasis)
    )] + $repositoryAuthorityBasis;
    $portablePublish = static function (array $expected, ?array $prior): array {
        if ($prior !== null) return $prior;
        $receipt = $expected;
        $receipt['publication_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-preview-candidate-publication/v1\0"
                . EnvironmentLifecycleCanon::encode($expected)
        );
        return $receipt;
    };
    $portableCleanup = static function (array $publication, array $sync, ?array $prior): array {
        if ($prior !== null) return $prior;
        $body = [
            'branch_commit' => $publication['branch_commit'],
            'branch_ref' => $publication['branch_ref'],
            'candidate_publication_receipt_sha256' =>
                $publication['publication_receipt_sha256'],
            'format' => 'duo-cloud-preview-candidate-cleanup/v1',
            'operation_id' => $publication['operation_id'],
            'repository_sync_receipt_sha256' => $sync['repository_sync_receipt_sha256'],
            'status' => 'absent',
        ];
        $body['cleanup_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-preview-candidate-cleanup/v1\0"
                . EnvironmentLifecycleCanon::encode($body)
        );
        return $body;
    };
    $portablePromotions = 0;
    $portablePromotionContexts = [];
    $portablePromote = static function (
        EnvironmentDriver $driver,
        array $frozen
    ) use (&$portablePromotions, &$portablePromotionContexts): array {
        $portablePromotions++;
        $portablePromotionContexts[] = $frozen;
        $applied = $driver->captureWp([
            'duo', 'portable-apply', '--manifest=' . $frozen['portable_export']['manifest_sha256'],
        ]);
        if (($applied['exit'] ?? 1) !== 0) throw new \RuntimeException('fixture portable apply failed');
        $body = [
            'base_containment_descriptor_sha256' =>
                $frozen['reviewed_base_containment']['descriptor_sha256'],
            'base_image_digest' => $frozen['reviewed_base']['image_digest'],
            'base_platform_fingerprint_sha256' => $frozen['reviewed_base']['platform_fingerprint_sha256'],
            'base_review_receipt_sha256' => $frozen['reviewed_base']['review_receipt_sha256'],
            'branch_commit' => $frozen['branch_commit'],
            'candidate_cleanup_receipt_sha256' =>
                $frozen['candidate_cleanup_receipt_sha256'],
            'candidate_publication_receipt_sha256' =>
                $frozen['candidate_publication_receipt_sha256'],
            'export_manifest_sha256' => $frozen['portable_export']['manifest_sha256'],
            'export_snapshot_hash' => $frozen['portable_export']['snapshot_hash'],
            'format' => 'duo-portable-preview-promotion-receipt/v1',
            'operation_id' => $frozen['operation_id'],
            'owner' => $frozen['promotion_owner'],
            'repository_authority_sha256' =>
                $frozen['repository_authority']['descriptor_sha256'],
            'repository_credential_helper_sha256' =>
                $frozen['repository_authority']['credential_helper_sha256'],
            'repository_receipt_sha256' => $frozen['repository_receipt_sha256'],
            'repository_sync_receipt_sha256' => $frozen['repository_sync_receipt_sha256'],
            'state_revision' => hash('sha256', 'portable-preview-state'),
            'status' => 'completed',
        ];
        $body['receipt_sha256'] = hash('sha256', EnvironmentLifecycleCanon::encode($body));
        return $body;
    };
    $portableObserve = static function (EnvironmentDriver $driver, ?array $evidence): array {
        if ($evidence !== null) return $evidence;
        $observed = $driver->captureWp(['duo', 'preview-status', '--format=json']);
        if (($observed['exit'] ?? 1) !== 0) throw new \RuntimeException('fixture observation failed');
        return [
            'containment_receipt_sha256' => hash('sha256', 'portable-containment'),
            'health' => 'ready',
        ];
    };
    $portableOptions = [
        'branch' => 'portable-candidate',
        'branch_commit' => $commit,
        'fidelity_omissions' => PortablePreviewMaterializer::REQUIRED_FIDELITY_OMISSIONS,
        'portable_export' => $portableExport,
        'repository_authority' => $repositoryAuthority,
        'reviewed_base' => $reviewedBase,
        'reviewed_base_containment' => $reviewedBaseContainment,
        'ttl_seconds' => 3600,
    ];
    $portableReceipt = PortablePreviewMaterializer::materialize(
        $portableDriver,
        $portableProvider,
        $journal,
        $portableOptions,
        $portablePublish,
        $portableCleanup,
        $portablePromote,
        $portableObserve
    );
    em_ok(($portableReceipt['format'] ?? null) === 'duo-portable-preview-receipt/v1'
        && ($portableReceipt['production_fidelity'] ?? null) === false
        && ($portableReceipt['fidelity']['level'] ?? null) === 'portable-authored-state'
        && ($portableReceipt['fidelity']['omissions'] ?? null)
            === PortablePreviewMaterializer::REQUIRED_FIDELITY_OMISSIONS
        && ($portableReceipt['base_containment_descriptor_sha256'] ?? null)
            === $reviewedBaseContainment['descriptor_sha256']
        && !array_key_exists('snapshot_set_id', $portableReceipt)
        && !array_key_exists('snapshot_set_receipt_sha256', $portableReceipt),
        'portable preview has a distinct receipt with mandatory physical/runtime fidelity omissions');
    em_ok(
        em_run(['git', 'symbolic-ref', '--short', 'HEAD'], $repo) === $operatorBranch
            && em_run(['git', 'rev-parse', 'HEAD'], $repo) === $operatorCommit
            && em_run(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $repo) === '',
        'portable preview consumes the isolated candidate ref without changing the operator checkout'
    );
    $portableActions = em_actions($portableLog);
    em_ok($portableActions === [
        'capabilities', 'create', 'mutation-acquire', 'mutation-read',
        'repository-sync', 'repository-materialize', 'url-set', 'inspect', 'ttl-set', 'ttl-read',
        'mutation-release',
    ] && array_values(array_filter(
        $portableActions,
        static fn(string $action): bool => str_starts_with($action, 'snapshot-')
    )) === [], 'portable preview creates a slot and never requests physical snapshot create/restore');
    em_ok(count($portableDriver->bindings) === 1
        && ($portableDriver->bindings[0]['fence']['state'] ?? null) === 'held'
        && array_column($portableDriver->calls, 'phase') === ['portable-promotion', 'portable-observation']
        && $portableDriver->releases === 1,
        'every portable target command is generation/fence-bound and local routing is revoked on release');
    em_ok($portablePromotions === 1
        && ($portablePromotionContexts[0]['branch_commit'] ?? null) === $commit
        && ($portablePromotionContexts[0]['portable_export']['manifest_sha256'] ?? null)
            === $portableExport['manifest_sha256']
        && ($portablePromotionContexts[0]['reviewed_base'] ?? null) === $reviewedBase,
        'portable promotion freezes the exact candidate, committed export, and reviewed clean base');
    em_ok(
        ($portablePromotionContexts[0]['reviewed_base_containment'] ?? null)
            === $reviewedBaseContainment,
        'portable promotion freezes the full reviewed-base containment descriptor'
    );
    $portableEvents = array_column(
        $journal->latestForTarget('branch-portable')['events'] ?? [],
        'event'
    );
    $observationIndex = array_search('target-observed', $portableEvents, true);
    $releaseIndex = array_search('target-fence-release-intent', $portableEvents, true);
    em_ok(is_int($observationIndex) && is_int($releaseIndex) && $observationIndex < $releaseIndex,
        'portable observation is durably journaled while the mutation fence is still held');

    $portableBeforeRetry = [
        count(em_actions($portableLog)), count($portableDriver->calls), $portablePromotions,
    ];
    $portableRetry = PortablePreviewMaterializer::materialize(
        $portableDriver,
        $portableProvider,
        $journal,
        $portableOptions,
        $portablePublish,
        $portableCleanup,
        $portablePromote,
        $portableObserve
    );
    em_ok(($portableRetry['resumed'] ?? false) === true
        && $portableBeforeRetry === [
            count(em_actions($portableLog)), count($portableDriver->calls), $portablePromotions,
        ], 'completed portable retry replays observation locally with zero provider or target contact');
    $portableLatest = $journal->latestForTarget('branch-portable');
    $portableActionsBeforeWrongReap = count(em_actions($portableLog));
    try {
        PortablePreviewMaterializer::reap(
            $portableDriver,
            $portableProvider,
            $journal,
            '20000101-000000-' . str_repeat('a', 24)
        );
        em_fail('portable reap selected a lifecycle newer than its public intent');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'expected reap operation'),
            'portable reap pins the expected lifecycle while holding the target journal lock');
    }
    em_ok(count(em_actions($portableLog)) === $portableActionsBeforeWrongReap,
        'a mismatched expected lifecycle refuses before provider contact');
    $portableReap = PortablePreviewMaterializer::reap(
        $portableDriver,
        $portableProvider,
        $journal,
        is_array($portableLatest) ? $portableLatest['operation_id'] : null
    );
    em_ok(($portableReap['disposition'] ?? null) === 'destroyed'
        && in_array('destroy', em_actions($portableLog), true)
        && !in_array('detach', em_actions($portableLog), true),
        'portable preview reuses exact generation/fence/TTL compare-and-destroy reap');

    // A controller can die after promotion/TTL but before its observation is
    // durable. Recovery must retain the same fence, skip the journaled
    // promotion, and reissue only the deterministic observation phase.
    $recoveryLog = $tmp . '/portable-recovery.log';
    $recoveryProvider = CommandEnvironmentProvider::fromEnvironment(
        'branch-portable-recovery',
        $cfg('ok', $recoveryLog)
    );
    $recoveryDriver = new PortableMaterializerDriver(
        'branch-portable-recovery',
        '/portable-recovery/repo'
    );
    $recoveryObservations = 0;
    $recoveryObserve = static function (
        EnvironmentDriver $driver,
        ?array $evidence
    ) use (&$recoveryObservations): array {
        if ($evidence !== null) return $evidence;
        $recoveryObservations++;
        $driver->captureWp(['duo', 'preview-status', '--format=json']);
        if ($recoveryObservations === 1) {
            throw new \RuntimeException('fixture lost observation response');
        }
        return [
            'containment_receipt_sha256' => hash('sha256', 'recovered-containment'),
            'health' => 'ready',
        ];
    };
    $recoveryPromotionBase = $portablePromotions;
    try {
        PortablePreviewMaterializer::materialize(
            $recoveryDriver,
            $recoveryProvider,
            $journal,
            $portableOptions,
            $portablePublish,
            $portableCleanup,
            $portablePromote,
            $recoveryObserve
        );
        em_fail('lost portable observation response was accepted');
    } catch (Throwable $e) {
        em_ok(str_contains($e->getMessage(), 'lost observation response'),
            'lost portable observation response leaves a recoverable held-fence journal');
    }
    em_ok($portablePromotions === $recoveryPromotionBase + 1
        && !in_array('mutation-release', em_actions($recoveryLog), true),
        'failure after portable promotion neither replays promotion nor releases unobserved state');
    $recoveryRetryDriver = new PortableMaterializerDriver(
        'branch-portable-recovery',
        '/portable-recovery/repo'
    );
    $recovered = PortablePreviewMaterializer::materialize(
        $recoveryRetryDriver,
        $recoveryProvider,
        $journal,
        $portableOptions,
        $portablePublish,
        $portableCleanup,
        $portablePromote,
        $recoveryObserve
    );
    $recoveryEvents = array_column(
        $journal->latestForTarget('branch-portable-recovery')['events'] ?? [],
        'event'
    );
    em_ok(($recovered['format'] ?? null) === 'duo-portable-preview-receipt/v1'
        && $portablePromotions === $recoveryPromotionBase + 1
        && count(array_filter(
            $recoveryEvents,
            static fn(string $event): bool => $event === 'portable-promotion-applied'
        )) === 1
        && array_slice(em_actions($recoveryLog), -3)
            === ['capabilities', 'mutation-read', 'mutation-release'],
        'held-fence recovery skips frozen promotion and resumes only observation then release');
    $recoveryReap = PortablePreviewMaterializer::reap(
        $recoveryRetryDriver,
        $recoveryProvider,
        $journal
    );
    em_ok(($recoveryReap['disposition'] ?? null) === 'destroyed',
        'recovered portable preview remains exactly reapable');

    chdir($old);
    echo "PASS: environment materializer orchestration regression\n";
} finally {
    if (isset($old) && getcwd() !== $old) chdir($old);
    em_remove($tmp);
}
}
