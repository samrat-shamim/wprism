<?php
declare(strict_types=1);

/* Offline host contract: live P is mandatory, target Git topology is checked,
 * and unresolved semantic rebase work never creates/replaces a source ref. */

namespace Duo\Orchestrator {
    final class RefreshPlan {
        public static array $roles = [];
        public static bool $resolved = false;
        public static function normalizeProductionSnapshot(array $export): array { return $export; }
        public static function compileGitWorktree(string $path, string $commit, string $role): array {
            self::$roles[] = $role;
            return ['commit' => $commit, 'format' => 'duo-refresh-compiled-test/v1', 'role' => $role];
        }
        public static function assertProductionCodeMatches(array $production, array $code): void {
            if (($production['completed_code']['revision'] ?? null) !== hash('sha256', 'code')) {
                throw new \RuntimeException('test code proof failed');
            }
        }
        public static function plan(array $base, array $production, array $branch, array $context): array {
            return ['context' => $context, 'format' => 'duo-refresh-plan/v1', 'plan_hash' => hash('sha256', 'test-plan')];
        }
        public static function normalizePlan(array $plan): array { return $plan; }
        public static function materialize(array $plan, string $worktree, array $resolution): array {
            // This intentionally leaves a conflict unresolved, proving the
            // host cannot create a branch merely because Git code replay ended.
            if (self::$resolved) {
                file_put_contents($worktree . '/state/rebased.json', json_encode(['resolution' => $resolution], JSON_THROW_ON_ERROR) . "\n");
            }
            return ['format' => 'duo-refresh-materialization/v1', 'plan_hash' => $plan['plan_hash'], 'resolved' => self::$resolved];
        }
        public static function validateMaterialization(array $receipt, array $plan, string $worktree): void {}
    }
}

namespace {
require dirname(__DIR__, 2) . '/cli/src/Transport.php';
require dirname(__DIR__, 2) . '/cli/src/Refresh.php';

use Duo\Orchestrator\Refresh;
use Duo\Orchestrator\Transport;

function fail_refresh(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function ok_refresh(bool $condition, string $message): void { if (!$condition) fail_refresh($message); echo "ok: $message\n"; }
function run_refresh(array $args, ?string $cwd = null): string {
    $command = implode(' ', array_map('escapeshellarg', $args));
    $out = []; $code = 0;
    exec(($cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $command . ' 2>&1', $out, $code);
    if ($code !== 0) fail_refresh("command failed ($code): $command\n" . implode("\n", $out));
    return trim(implode("\n", $out));
}
function remove_refresh(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') remove_refresh($path . '/' . $name);
    @rmdir($path);
}

final class RefreshTransport extends Transport {
    public array $raw = [];
    public array $wp = [];
    public function __construct(private string $head, private string $export, private string $trackedStatus = '') {
        parent::__construct('production', ['repo_path' => '/target/repository']);
    }
    public function describe(): string { return 'test'; }
    protected function wpCommand(array $wpArgs): string { return 'false'; }
    protected function rawCommand(string $script): string { return 'false'; }
    public function captureRaw(string $script): array {
        $this->raw[] = $script;
        return ['exit' => 0, 'stdout' => $this->head . "\n" . $this->trackedStatus, 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        $this->wp[] = $wpArgs;
        return ['exit' => 0, 'stdout' => $this->export . "\n", 'stderr' => ''];
    }
}

$tmp = sys_get_temp_dir() . '/duo-refresh-orchestration-' . bin2hex(random_bytes(6));
$repo = $tmp . '/repo';
mkdir($repo, 0700, true);
try {
    run_refresh(['git', 'init', '-b', 'main'], $repo);
    run_refresh(['git', 'config', 'user.email', 'test@example.invalid'], $repo);
    run_refresh(['git', 'config', 'user.name', 'Refresh Test'], $repo);
    mkdir($repo . '/state', 0700, true);
    file_put_contents($repo . '/state/base.json', "{}\n");
    file_put_contents($repo . '/prod.txt', "base\n");
    file_put_contents($repo . '/feature.txt', "base\n");
    run_refresh(['git', 'add', '.'], $repo); run_refresh(['git', 'commit', '-m', 'base'], $repo);
    $base = run_refresh(['git', 'rev-parse', 'HEAD'], $repo);
    run_refresh(['git', 'branch', 'production'], $repo);
    run_refresh(['git', 'checkout', 'production'], $repo);
    file_put_contents($repo . '/prod.txt', "production\n");
    run_refresh(['git', 'add', 'prod.txt'], $repo); run_refresh(['git', 'commit', '-m', 'production'], $repo);
    $production = run_refresh(['git', 'rev-parse', 'HEAD'], $repo);
    run_refresh(['git', 'checkout', '-b', 'feature', $base], $repo);
    file_put_contents($repo . '/feature.txt', "feature\n");
    run_refresh(['git', 'add', 'feature.txt'], $repo); run_refresh(['git', 'commit', '-m', 'feature'], $repo);
    $feature = run_refresh(['git', 'rev-parse', 'HEAD'], $repo);

    $export = json_encode([
        'completed_code' => ['descriptor' => ['format' => 'duo-code/v1'], 'revision' => hash('sha256', 'code')],
        'format' => 'duo-refresh-production/v1',
        'media' => [],
        'policy' => ['manifest_hash' => hash('sha256', 'manifest')],
        'records' => ['post:one' => ['content' => ['uuid' => 'one'], 'hash' => hash('sha256', 'record'), 'identity' => 'post:one', 'path' => 'state/posts/one.json', 'type' => 'post']],
        'repository' => ['compiler' => ['format' => 'duo-refresh-repository/v1']],
        'snapshot_hash' => hash('sha256', 'production-snapshot'),
    ], JSON_THROW_ON_ERROR);
    $transport = new RefreshTransport($production, $export);
    $old = getcwd(); chdir($repo);
    $result = Refresh::refresh($transport, 'production');
    ok_refresh(($result['context']['base_commit'] ?? null) === $base, 'merge-base is the explicit B input');
    ok_refresh(($result['context']['branch_commit'] ?? null) === $feature, 'branch HEAD is W input');
    ok_refresh(($result['context']['production_commit'] ?? null) === $production, 'target HEAD must equal production-ref');
    ok_refresh(is_file($result['plan_path']), 'immutable refresh plan is persisted locally');
    ok_refresh($transport->wp === [['duo', 'refresh-export', '--repo=/target/repository', '--format=json']], 'P is obtained only through refresh-export');
    ok_refresh(in_array('base', \Duo\Orchestrator\RefreshPlan::$roles, true)
        && in_array('branch', \Duo\Orchestrator\RefreshPlan::$roles, true)
        && in_array('production-code', \Duo\Orchestrator\RefreshPlan::$roles, true), 'Git artifacts are compiled by role, with production code-only');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'refresh leaves source checkout/ref untouched');
    $dirty = new RefreshTransport($production, $export, " M site.duo.json\n");
    try {
        Refresh::refresh($dirty, 'production');
        fail_refresh('tracked target repository state was accepted');
    } catch (Throwable $e) {
        ok_refresh(str_contains($e->getMessage(), 'tracked changes') && $dirty->wp === [], 'target tracked state refuses before refresh-export');
    }

    try {
        Refresh::rebase($transport, 'production', 'refresh-candidate', ['strategy' => 'manual', 'records' => []]);
        fail_refresh('unresolved planner materialization created a branch');
    } catch (Throwable $e) {
        ok_refresh(str_contains($e->getMessage(), 'resolved'), 'unresolved semantic materialization refuses before branch creation: ' . $e->getMessage());
    }
    $exists = []; $status = 0; exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet refs/heads/refresh-candidate', $exists, $status);
    ok_refresh($status === 1, 'unresolved rebase creates no requested ref');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'failed rebase preserves source branch usability');
    $runs = glob($repo . '/.git/duo-refresh/runs/*/run.json') ?: [];
    ok_refresh(count($runs) === 1, 'failed candidate has a durable recovery run journal');
    $runId = basename(dirname($runs[0]));
    Refresh::abort($runId);
    ok_refresh(!is_dir($repo . '/.git/duo-refresh/worktrees/' . $runId), 'abort removes only journal-owned candidate worktree');
    \Duo\Orchestrator\RefreshPlan::$resolved = true;
    $complete = Refresh::rebase($transport, 'production', 'refresh-complete', ['strategy' => 'ours', 'records' => []]);
    ok_refresh(run_refresh(['git', 'rev-parse', 'refresh-complete'], $repo) === $complete['head'], 'strictly validated candidate is atomically published as a new ref');
    ok_refresh(run_refresh(['git', 'show', 'refresh-complete:state/rebased.json'], $repo) !== '', 'semantic state materialization is committed on candidate only');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'successful rebase still preserves source checkout/ref');
    $completeRun = json_decode((string) file_get_contents($repo . '/.git/duo-refresh/runs/' . $complete['run_id'] . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    ok_refresh(($completeRun['resolution']['strategy'] ?? null) === 'ours', 'declared conflict strategy is immutable run evidence');
    ok_refresh(!is_dir($repo . '/.git/duo-refresh/worktrees/' . $complete['run_id']), 'successful rebase cleans only its disposable worktree');
    chdir($old);
    echo "PASS: refresh host orchestration regression\n";
} finally {
    if (isset($old) && getcwd() !== $old) chdir($old);
    remove_refresh($tmp);
}
}
