<?php
declare(strict_types=1);

/* Offline host contract: live P is mandatory, target Git topology is checked,
 * and unresolved semantic rebase work never creates/replaces a source ref. */

namespace Duo\Orchestrator {
    require_once dirname(__DIR__, 2) . '/agent/src/Canon.php';
    require_once dirname(__DIR__, 2) . '/cli/src/RefreshFieldDiff.php';

    final class RefreshPlan {
        public static array $roles = [];
        public static bool $resolved = false;
        public static int $interactiveFieldCalls = 0;
        public static bool $interactiveCancels = true;
        public static int $validatedFieldDiffs = 0;
        public static ?array $lastInteractivePresentation = null;
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
        public static function fieldDiff(array $plan, array $base, array $productionCode, array $branch): array {
            $diff = self::fieldDiffDocument((string) $plan['plan_hash']);
            return [
                'diff' => $diff,
                'bundle' => [
                    'algorithm' => 'duo-refresh-field-diff/v1',
                    'diff' => $diff,
                    'diff_hash' => $diff['diff_hash'],
                    'plan_hash' => $plan['plan_hash'],
                    'private_bundle_sentinel' => 'PRIVATE-BUNDLE-SENTINEL-OMITTED',
                    'records' => [],
                ],
            ];
        }
        public static function validateFieldDiff(array $diff): array {
            self::$validatedFieldDiffs++;
            return RefreshFieldDiff::validateDiff($diff);
        }
        public static function readFieldResolution(array $diff, string $path): array { return []; }
        /** Private-only host presentation seam; it is never part of the fake diff/bundle. */
        public static function interactiveFieldPresentation(array $plan, array $diff, array $bundle): array {
            return [
                'auto' => [],
                'diff_hash' => $diff['diff_hash'],
                'format' => RefreshFieldDiff::PRESENTATION_FORMAT,
                'labels' => [],
                'plan_hash' => $diff['plan_hash'],
            ];
        }
        public static function interactiveFieldResolution(array $diff, $in, $out, ?array $presentation = null): ?array {
            self::$interactiveFieldCalls++;
            self::$lastInteractivePresentation = $presentation;
            return self::$interactiveCancels ? null : RefreshFieldDiff::resolution($diff, []);
        }
        public static function fieldDiffPolicyFromGitWorktree(string $path, string $commit, string $label): array { return []; }
        public static function assertFieldCandidatePolicy(array $bundle, array $projection): void {}
        public static function materializeFieldResolved(array $plan, string $worktree, array $bundle, array $resolution): array {
            $receipt = self::materialize($plan, $worktree, $resolution);
            $receipt['field_diff_hash'] = $bundle['diff_hash'];
            $receipt['field_resolution_hash'] = $resolution['resolution_hash'];
            return $receipt;
        }
        private static function fieldDiffDocument(string $planHash): array {
            $diff = [
                'algorithm' => 'duo-refresh-field-diff/v1',
                'authority' => false,
                'choices' => ['ours' => 'branch', 'theirs' => 'production'],
                'format' => 'duo-refresh-field-diff/v1',
                'plan_hash' => $planHash,
                'policy_projection_hashes' => [
                    'base' => hash('sha256', 'orchestration-base-policy'),
                    'branch' => hash('sha256', 'orchestration-reviewed-policy'),
                    'production' => hash('sha256', 'orchestration-reviewed-policy'),
                ],
                'production_snapshot_hash' => hash('sha256', 'production-snapshot'),
                'redaction' => 'values_omitted',
                'records' => [],
                'roles' => ['base' => 'merge_base', 'ours' => 'branch', 'theirs' => 'production'],
                'summary' => ['atomic_records' => 0, 'changes' => 0, 'conflicting_choices' => 0, 'records' => 0],
            ];
            $diff['diff_hash'] = hash('sha256', \Duo\Canon::encode($diff));
            return $diff;
        }
    }
}

namespace {
require dirname(__DIR__, 2) . '/cli/src/Transport.php';
require dirname(__DIR__, 2) . '/cli/src/CodeDeploy.php';
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
    private ?string $exportAfterNextRead = null;
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
        $export = $this->export;
        if ($this->exportAfterNextRead !== null) {
            $this->export = $this->exportAfterNextRead;
            $this->exportAfterNextRead = null;
        }
        return ['exit' => 0, 'stdout' => $export . "\n", 'stderr' => ''];
    }
    public function replaceAfterNextExport(string $export): void { $this->exportAfterNextRead = $export; }
    public function replaceCurrentExport(string $export): void { $this->export = $export; }
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
    $exportCall = $transport->wp[0] ?? [];
    ok_refresh(count($transport->wp) === 1
        && in_array('--skip-plugins', $exportCall, true)
        && in_array('--skip-themes', $exportCall, true)
        && count(array_filter($exportCall, static fn(string $arg): bool => str_starts_with($arg, '--exec=')
            && str_contains($arg, 'DUO_CONTROL_PLANE')
            && str_contains($arg, 'after_wp_config_load'))) === 1
        && array_slice($exportCall, -4) === ['duo', 'refresh-export', '--repo=/target/repository', '--format=json'],
        'P is obtained through refresh-export under the isolated control bootstrap');
    ok_refresh(count(array_filter($transport->raw, static fn(string $script): bool =>
        str_contains($script, '--untracked-files=all')
        && str_contains($script, 'ls-files --others --ignored --exclude-standard')
        && str_contains($script, 'state media code manifests'))) > 0,
        'target verifier includes untracked and ignored canonical compiler inputs');
    ok_refresh(in_array('base', \Duo\Orchestrator\RefreshPlan::$roles, true)
        && in_array('branch', \Duo\Orchestrator\RefreshPlan::$roles, true)
        && in_array('production-code', \Duo\Orchestrator\RefreshPlan::$roles, true), 'Git artifacts are compiled by role, with production code-only');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'refresh leaves source checkout/ref untouched');
    $dirty = new RefreshTransport($production, $export, "?? state/deletions/untracked.json\n");
    try {
        Refresh::refresh($dirty, 'production');
        fail_refresh('tracked target repository state was accepted');
    } catch (Throwable $e) {
        ok_refresh(str_contains($e->getMessage(), 'untracked') && $dirty->wp === [],
            'untracked canonical target state refuses before refresh-export');
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

    $runsBeforeCancel = glob($repo . '/.git/duo-refresh/runs/*/run.json') ?: [];
    try {
        Refresh::rebase($transport, 'production', 'refresh-interactive-cancel', ['strategy' => 'manual', 'records' => []], null, null, true);
        fail_refresh('interactive cancellation unexpectedly returned');
    } catch (\Duo\Orchestrator\RefreshFieldResolutionCancelled $e) {
        ok_refresh(str_contains($e->getMessage(), 'no run record, candidate worktree, branch, or ref was created'),
            'interactive cancellation explicitly guarantees no run record, candidate worktree, branch, or ref');
    }
    $cancelExists = []; $cancelStatus = 0;
    exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet refs/heads/refresh-interactive-cancel', $cancelExists, $cancelStatus);
    ok_refresh($cancelStatus === 1 && \Duo\Orchestrator\RefreshPlan::$interactiveFieldCalls === 1,
        'interactive EOF/cancel creates no requested ref after resolving the redacted diff');
    $runsAfterCancel = glob($repo . '/.git/duo-refresh/runs/*/run.json') ?: [];
    $cancelWorktrees = glob($repo . '/.git/duo-refresh/worktrees/*') ?: [];
    $cancelDiffs = glob($repo . '/.git/duo-refresh/field-diffs/*.json') ?: [];
    ok_refresh($runsAfterCancel === $runsBeforeCancel && $cancelWorktrees === [] && $cancelDiffs !== [],
        'interactive cancellation creates no run journal or candidate worktree while retaining only immutable planning/diff artifacts');

    $readsBeforeExplicitManual = count($transport->wp);
    try {
        Refresh::rebase($transport, 'production', 'refresh-explicit-manual', ['strategy' => 'manual', 'records' => []], null, null, true, true);
        fail_refresh('explicit legacy manual strategy was accepted with interactive resolution');
    } catch (\RuntimeException $e) {
        ok_refresh(str_contains($e->getMessage(), 'cannot be mixed'),
            'field mode rejects an explicitly supplied legacy --strategy=manual');
    }
    ok_refresh(count($transport->wp) === $readsBeforeExplicitManual,
        'explicit legacy strategy refusal happens before another target read or scratch candidate');

    \Duo\Orchestrator\RefreshPlan::$resolved = true;
    $complete = Refresh::rebase($transport, 'production', 'refresh-complete', ['strategy' => 'ours', 'records' => []]);
    ok_refresh(run_refresh(['git', 'rev-parse', 'refresh-complete'], $repo) === $complete['head'], 'strictly validated candidate is atomically published as a new ref');
    ok_refresh(run_refresh(['git', 'show', 'refresh-complete:state/rebased.json'], $repo) !== '', 'semantic state materialization is committed on candidate only');
    ok_refresh(run_refresh(['git', 'rev-parse', 'HEAD'], $repo) === $feature && run_refresh(['git', 'branch', '--show-current'], $repo) === 'feature', 'successful rebase still preserves source checkout/ref');
    $completeRun = json_decode((string) file_get_contents($repo . '/.git/duo-refresh/runs/' . $complete['run_id'] . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    ok_refresh(($completeRun['resolution']['strategy'] ?? null) === 'ours', 'declared conflict strategy is immutable run evidence');
    ok_refresh(!is_dir($repo . '/.git/duo-refresh/worktrees/' . $complete['run_id']), 'successful rebase cleans only its disposable worktree');

    // The strict v1 public diff seam is exercised through a real host run:
    // the private bundle is intentionally marked with a sentinel so neither
    // the public diff journal nor the run evidence can accidentally serialize
    // it. This fake materializer still drives code replay, state commit, and
    // new-ref publication through Refresh itself.
    \Duo\Orchestrator\RefreshPlan::$interactiveCancels = false;
    $fieldComplete = Refresh::rebase(
        $transport,
        'production',
        'refresh-field-complete',
        ['strategy' => 'manual', 'records' => []],
        null,
        null,
        true
    );
    $fieldRunPath = $repo . '/.git/duo-refresh/runs/' . $fieldComplete['run_id'] . '/run.json';
    $fieldRunBytes = (string) file_get_contents($fieldRunPath);
    $fieldRun = json_decode($fieldRunBytes, true, 512, JSON_THROW_ON_ERROR);
    $fieldDiffPath = (string) ($fieldComplete['field_diff_path'] ?? '');
    $fieldDiffBytes = $fieldDiffPath === '' ? '' : (string) file_get_contents($fieldDiffPath);
    $fieldDiffJournal = $fieldDiffBytes === '' ? [] : json_decode($fieldDiffBytes, true, 512, JSON_THROW_ON_ERROR);
    $fieldReceiptEvents = glob($repo . '/.git/duo-refresh/runs/' . $fieldComplete['run_id'] . '/events/*-state-materialized.json') ?: [];
    $fieldReceipt = $fieldReceiptEvents === [] ? [] : json_decode((string) file_get_contents($fieldReceiptEvents[0]), true, 512, JSON_THROW_ON_ERROR);
    ok_refresh(
        run_refresh(['git', 'rev-parse', 'refresh-field-complete'], $repo) === $fieldComplete['head']
            && \Duo\Orchestrator\RefreshPlan::$validatedFieldDiffs >= 2
            && ($fieldRun['field_diff_hash'] ?? null) === ($fieldDiffJournal['diff_hash'] ?? null)
            && ($fieldRun['field_resolution']['resolution_hash'] ?? null) === ($fieldReceipt['data']['receipt']['field_resolution_hash'] ?? null),
        'successful field-mode host run validates the closed diff, publishes a new ref, and binds exact diff/resolution hashes'
    );
    ok_refresh(
        !str_contains($fieldRunBytes, 'PRIVATE-BUNDLE-SENTINEL-OMITTED')
            && !str_contains($fieldDiffBytes, 'PRIVATE-BUNDLE-SENTINEL-OMITTED')
            && (\Duo\Orchestrator\RefreshPlan::$lastInteractivePresentation['format'] ?? null) === \Duo\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT
            && !str_contains($fieldRunBytes, \Duo\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT)
            && !str_contains($fieldDiffBytes, \Duo\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT)
            && !str_contains(\Duo\Canon::encode($fieldReceipt), \Duo\Orchestrator\RefreshFieldDiff::PRESENTATION_FORMAT),
        'field run evidence, public diff, and receipt omit private bundle and interactive-presentation data'
    );

    // Choices are collected before Refresh's mandatory second production
    // observation. A snapshot change must stop before a run journal,
    // candidate worktree, or new ref exists.
    $changedExport = json_decode($export, true, 512, JSON_THROW_ON_ERROR);
    $changedExport['snapshot_hash'] = hash('sha256', 'production-snapshot-moved');
    $transport->replaceAfterNextExport(json_encode($changedExport, JSON_THROW_ON_ERROR));
    $runsBeforeStale = glob($repo . '/.git/duo-refresh/runs/*/run.json') ?: [];
    try {
        Refresh::rebase(
            $transport,
            'production',
            'refresh-field-stale-snapshot',
            ['strategy' => 'manual', 'records' => []],
            null,
            null,
            true
        );
        fail_refresh('field choices were applied after the production snapshot moved');
    } catch (\RuntimeException $e) {
        ok_refresh(str_contains($e->getMessage(), 'production changed after refresh planning'),
            'second production observation refuses a stale field-resolution plan');
    }
    $staleExists = []; $staleStatus = 0;
    exec('git -C ' . escapeshellarg($repo) . ' show-ref --verify --quiet refs/heads/refresh-field-stale-snapshot', $staleExists, $staleStatus);
    $runsAfterStale = glob($repo . '/.git/duo-refresh/runs/*/run.json') ?: [];
    $staleWorktrees = glob($repo . '/.git/duo-refresh/worktrees/*') ?: [];
    ok_refresh($staleStatus === 1 && $runsAfterStale === $runsBeforeStale && $staleWorktrees === [],
        'stale field resolution creates no run record, candidate worktree, or requested ref');

    // After a field-mode run starts, Refresh retains the candidate/recovery
    // evidence but changes the public exception type to a run-id-only handle.
    // The detailed planner reason remains in the private local event journal.
    $transport->replaceCurrentExport($export);
    \Duo\Orchestrator\RefreshPlan::$resolved = false;
    $failedFieldRun = null;
    try {
        Refresh::rebase(
            $transport,
            'production',
            'refresh-field-private-failure',
            ['strategy' => 'manual', 'records' => []],
            null,
            null,
            true
        );
        fail_refresh('post-run field failure unexpectedly returned');
    } catch (\Duo\Orchestrator\RefreshFieldResolutionRunFailed $e) {
        $failedFieldRun = $e->runId();
        ok_refresh(!str_contains($e->getMessage(), 'semantic planner')
            && preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $failedFieldRun) === 1,
            'post-run field failure exposes only a safe run-id recovery handle');
    }
    $failedEvents = $failedFieldRun === null ? [] : glob($repo . '/.git/duo-refresh/runs/' . $failedFieldRun . '/events/*-stopped.json');
    $failedEventBytes = $failedEvents === [] ? '' : (string) file_get_contents($failedEvents[0]);
    ok_refresh(str_contains($failedEventBytes, 'semantic planner did not return a resolved'),
        'post-run field failure preserves the detailed cause only in private event evidence');
    if ($failedFieldRun !== null) Refresh::abort($failedFieldRun);
    chdir($old);
    echo "PASS: refresh host orchestration regression\n";
} finally {
    if (isset($old) && getcwd() !== $old) chdir($old);
    remove_refresh($tmp);
}
}
