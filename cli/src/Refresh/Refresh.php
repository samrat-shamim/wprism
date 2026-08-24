<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Policy/ScopeContract.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../Code/CodeResolver.php';

/** Explicit non-error terminal for `duo rebase --interactive` cancellation. */
final class RefreshFieldResolutionCancelled extends \RuntimeException {}

/**
 * Field-mode failures after a durable run starts are intentionally opaque to
 * the CLI. The detailed cause is preserved in the local private event journal;
 * callers get only the safe recovery handle needed for --abort.
 */
final class RefreshFieldResolutionRunFailed extends \RuntimeException {
    public function __construct(private string $refreshRunId) {
        parent::__construct('redacted field-level resolution stopped after candidate setup');
    }

    public function runId(): string {
        return $this->refreshRunId;
    }
}

/**
 * Host-side production refresh/rebase orchestration.
 *
 * This class intentionally does not turn a Git ref into "production truth".
 * It gets that truth only from the target's read-only refresh-export command,
 * then hands three normalized inputs to the semantic planner:
 *
 *   B: git merge-base(branch HEAD, verified production ref)
 *   P: a live `duo-refresh-production/v1` export
 *   W: the branch's compiled repository
 *
 * RefreshPlan is deliberately a separate, pure semantic boundary.  Its exact
 * required static API is documented in cli/README.md and checked before work
 * which could create a candidate worktree or ref.  In particular, this shell
 * never falls back to raw Git three-way state merging.
 */
final class Refresh {
    private const EXPORT_FORMAT = 'duo-refresh-production/v1';
    private const PLAN_FORMAT = 'duo-refresh-plan/v1';
    private const MATERIALIZATION_FORMAT = 'duo-refresh-materialization/v1';
    private const FIELD_DIFF_FORMAT = 'duo-refresh-field-diff/v1';
    private const FIELD_RESOLUTION_FORMAT = 'duo-refresh-field-resolution/v1';

    /**
     * Fetch and persist an immutable semantic refresh plan.  This is read-only
     * with respect to the production target; local temporary worktrees are
     * removed before the method returns.
     *
     * @return array<string,mixed>
     */
    public static function refresh(
        EnvironmentDriver $transport,
        string $productionRef,
        ?array $scopeContract = null,
        bool $includeFieldDiff = false
    ): array {
        $prepared = self::prepare($transport, $productionRef, $scopeContract, $includeFieldDiff);
        return [
            'plan' => $prepared['plan'],
            'plan_path' => $prepared['plan_path'],
            'code_resolve' => $prepared['code_resolve'] ?? [],
            'context' => $prepared['context'],
            'field_diff' => $prepared['field_diff'],
            'field_diff_path' => $prepared['field_diff_path'],
            'field_diff_unavailable' => $prepared['field_diff_unavailable'],
        ];
    }

    /**
     * Construct a new ref only after a planner materializes and strictly
     * validates semantic state in an isolated Git worktree.  The caller's
     * checkout/ref is never checked out, reset, or updated.
     *
     * @return array{plan_path:string,run_id:string,new_branch:string,head:string}
     */
    public static function rebase(
        EnvironmentDriver $transport,
        string $productionRef,
        string $newBranch,
        array $resolution = [],
        ?array $scopeContract = null,
        ?string $fieldResolutionPath = null,
        bool $interactive = false,
        bool $legacyStrategyExplicit = false
    ): array {
        self::requirePlanner(['normalizeProductionSnapshot', 'compileGitWorktree', 'assertProductionCodeMatches', 'plan', 'normalizePlan', 'materialize', 'validateMaterialization']);
        $resolution = self::normalizeResolution($resolution);
        if (($fieldResolutionPath !== null || $interactive)
            && ($legacyStrategyExplicit || $resolution['strategy'] !== 'manual' || $resolution['records'] !== [])) {
            throw new \RuntimeException('field resolution cannot be mixed with --strategy or --resolve');
        }
        if ($fieldResolutionPath !== null && $interactive) {
            throw new \RuntimeException('field resolution file and --interactive cannot be combined');
        }
        if (($fieldResolutionPath !== null || $interactive) && $scopeContract !== null) {
            throw new \RuntimeException('field-level resolution is unavailable for scoped refresh; use legacy --strategy/--resolve');
        }
        $prepared = self::prepare($transport, $productionRef, $scopeContract, $fieldResolutionPath !== null || $interactive);
        $fieldResolution = null;
        $fieldBundle = null;
        if ($fieldResolutionPath !== null || $interactive) {
            $fieldPlannerMethods = [
                'fieldDiffPolicyFromGitWorktree', 'assertFieldCandidatePolicy', 'materializeFieldResolved',
            ];
            if ($fieldResolutionPath !== null) {
                $fieldPlannerMethods[] = 'readFieldResolution';
            } else {
                $fieldPlannerMethods[] = 'interactiveFieldPresentation';
                $fieldPlannerMethods[] = 'interactiveFieldResolution';
            }
            self::requirePlanner($fieldPlannerMethods);
            if (!is_array($prepared['field_diff'] ?? null) || !is_array($prepared['field_bundle'] ?? null)) {
                throw new \RuntimeException('field-level resolution is unavailable for this refresh plan');
            }
            $fieldBundle = $prepared['field_bundle'];
            if ($fieldResolutionPath !== null) {
                $fieldResolution = self::planner('readFieldResolution', [$prepared['field_diff'], $fieldResolutionPath]);
            } else {
                // Presentation is a deliberately transient local TTY surface:
                // never put it in $prepared, a journal, receipt, or result.
                $presentation = self::planner('interactiveFieldPresentation', [
                    $prepared['plan'], $prepared['field_diff'], $fieldBundle,
                ]);
                $fieldResolution = self::planner('interactiveFieldResolution', [
                    $prepared['field_diff'], STDIN, STDOUT, $presentation,
                ]);
            }
            if ($fieldResolution === null) {
                throw new RefreshFieldResolutionCancelled('field resolution cancelled; no run record, candidate worktree, branch, or ref was created');
            }
            if (!is_array($fieldResolution) || ($fieldResolution['format'] ?? null) !== self::FIELD_RESOLUTION_FORMAT
                || !self::isHash($fieldResolution['resolution_hash'] ?? null)) {
                throw new \RuntimeException('field resolution did not produce a normalized v1 contract');
            }
        }
        $context = $prepared['context'];
        $root = (string) $context['repo_root'];
        self::assertNewBranch($root, $newBranch);

        // Re-read production immediately before local materialization.  A plan
        // from a prior live snapshot must never silently become a new baseline.
        $again = self::readProduction($transport, (string) $context['production_commit'], $scopeContract);
        if (($again['snapshot_hash'] ?? null) !== ($context['production_snapshot_hash'] ?? null)) {
            throw new \RuntimeException(
                'production changed after refresh planning; no candidate branch was created (run duo refresh/rebase again)'
            );
        }

        $runId = self::runId();
        $journal = new RefreshRunJournal(self::journalRoot($root));
        $worktree = $journal->worktreePath($runId);
        $run = [
            'base_commit' => $context['base_commit'],
            'branch_commit' => $context['branch_commit'],
            'created_at' => gmdate('c'),
            'format' => 'duo-refresh-run/v1',
            'kind' => 'rebase',
            'new_branch' => $newBranch,
            'original_branch' => $context['branch_name'],
            'plan_hash' => $context['plan_hash'],
            'plan_path' => $prepared['plan_path'],
            'production_commit' => $context['production_commit'],
            'production_env' => $transport->name(),
            'production_snapshot_hash' => $context['production_snapshot_hash'],
            'source_head' => $context['branch_commit'],
            'worktree' => $worktree,
        ];
        if ($fieldResolution !== null) {
            $run['field_diff_hash'] = (string) ($prepared['field_diff']['diff_hash'] ?? '');
            $run['field_diff_path'] = (string) ($prepared['field_diff_path'] ?? '');
            $run['field_resolution'] = $fieldResolution;
        } else {
            $run['resolution'] = $resolution;
        }
        if ($scopeContract !== null) {
            $run['scope_hash'] = (string) ($scopeContract['scope_hash'] ?? '');
        }
        $journal->start($runId, $run); // durable before `git worktree add`

        try {
            self::git($root, ['worktree', 'add', '--detach', $worktree, (string) $context['branch_commit']]);
            $candidateCode = array_filter(['candidate' => self::materializeLockedCode($worktree)]);
            $journal->append($runId, 'worktree-created', ['worktree' => $worktree]);

            if ($scopeContract === null) {
                self::rebaseCodeOnly(
                    $worktree,
                    (string) $context['production_commit'],
                    (string) $context['base_commit'],
                    $journal->runDir($runId)
                );
                self::assertNoUnmerged($worktree);
                $journal->append($runId, 'code-rebased', ['head' => self::gitStdout($worktree, ['rev-parse', 'HEAD'])]);
            } else {
                // duo-scope-contract/v1 explicitly excludes code and
                // lifecycle. Keep the branch commit's code bytes/ancestry;
                // only the scoped state overlay below may change paths.
                $journal->append($runId, 'code-preserved', [
                    'head' => self::gitStdout($worktree, ['rev-parse', 'HEAD']),
                    'scope_hash' => (string) $scopeContract['scope_hash'],
                ]);
            }

            if ($fieldResolution !== null) {
                $candidatePolicy = self::planner('fieldDiffPolicyFromGitWorktree', [
                    $worktree, self::gitStdout($worktree, ['rev-parse', 'HEAD']), 'candidate',
                ]);
                self::planner('assertFieldCandidatePolicy', [$fieldBundle, $candidatePolicy]);
                $journal->append($runId, 'field-policy-verified', [
                    'projection_hash' => $candidatePolicy['projection_hash'] ?? null,
                ]);
                self::assertAuthorizingPlan($prepared['plan']);
                $receipt = self::planner('materializeFieldResolved', [
                    $prepared['plan'], $worktree, $fieldBundle, $fieldResolution,
                ]);
            } else {
                self::assertAuthorizingPlan($prepared['plan']);
                $receipt = self::planner('materialize', [$prepared['plan'], $worktree, $resolution]);
            }
            if (!is_array($receipt)
                || ($receipt['format'] ?? null) !== self::MATERIALIZATION_FORMAT
                || ($receipt['plan_hash'] ?? null) !== $context['plan_hash']
                || ($receipt['resolved'] ?? null) !== true) {
                throw new \RuntimeException(
                    'semantic planner did not return a resolved duo-refresh-materialization/v1 receipt; candidate worktree retained for recovery'
                );
            }
            if ($fieldResolution !== null && (!hash_equals(
                (string) $fieldResolution['resolution_hash'],
                (string) ($receipt['field_resolution_hash'] ?? '')
            ) || !hash_equals(
                (string) $prepared['field_diff']['diff_hash'],
                (string) ($receipt['field_diff_hash'] ?? '')
            ))) {
                throw new \RuntimeException('field materialization receipt does not bind the exact diff and resolution');
            }
            self::assertOnlyStatePathsChanged($worktree);
            self::planner('validateMaterialization', [$receipt, $prepared['plan'], $worktree]);
            self::assertOnlyStatePathsChanged($worktree);
            self::assertNoUnmerged($worktree);
            $journal->append($runId, 'state-materialized', ['receipt' => $receipt]);

            self::commitMaterializedState($worktree, (string) $context['plan_hash']);
            $candidate = self::gitStdout($worktree, ['rev-parse', 'HEAD']);
            self::assertOriginalStillUsable($root, $context);
            self::createNewRef($root, $newBranch, $candidate);
            $journal->append($runId, 'branch-created', ['head' => $candidate, 'new_branch' => $newBranch]);

            self::removeWorktree($root, $worktree);
            $journal->append($runId, 'complete', ['head' => $candidate]);
            return [
                'plan_path' => $prepared['plan_path'],
                'run_id' => $runId,
                'new_branch' => $newBranch,
                'head' => $candidate,
                // Both halves: the three worktrees prepare() built, and the
                // candidate this method built. A rebase materializes four.
                'code_resolve' => ($prepared['code_resolve'] ?? []) + $candidateCode,
                'field_diff_path' => $fieldResolution === null ? null : $prepared['field_diff_path'],
            ];
        } catch (\Throwable $e) {
            // Do not remove the worktree after a partial local operation: the
            // immutable run/event journal is the recovery receipt.  It can be
            // safely discarded with `duo rebase <env> --abort=<run-id>`; the
            // source branch/ref has not been touched.
            try {
                $journal->append($runId, 'stopped', ['reason' => $e->getMessage()]);
            } catch (\Throwable) {
                // Preserve the field-mode public boundary even if the
                // recovery journal itself has an unexpected local I/O error.
                // The retained run id is still the only safe operator handle.
                if ($fieldResolution !== null) {
                    throw new RefreshFieldResolutionRunFailed($runId);
                }
                throw $e;
            }
            if ($fieldResolution !== null) {
                throw new RefreshFieldResolutionRunFailed($runId);
            }
            throw $e;
        }
    }

    /** Remove only the journal-owned candidate worktree; never a source ref. */
    public static function abort(string $runId): void {
        $root = self::repositoryRoot();
        $journal = new RefreshRunJournal(self::journalRoot($root));
        $run = $journal->read($runId);
        if (($run['kind'] ?? null) !== 'rebase') {
            throw new \RuntimeException("refresh run '$runId' is not a rebase run");
        }
        $worktree = $journal->worktreePath($runId);
        if (($run['worktree'] ?? null) !== $worktree) {
            throw new \RuntimeException("refresh run '$runId' has an unsafe worktree path");
        }
        if (is_dir($worktree) || is_file($worktree . '/.git')) {
            self::removeWorktree($root, $worktree);
        }
        $journal->append($runId, 'aborted', []);
    }

    /** @return array<string,mixed> */
    private static function prepare(
        EnvironmentDriver $transport,
        string $productionRef,
        ?array $scopeContract = null,
        bool $includeFieldDiff = false
    ): array {
        self::requirePlanner(['normalizeProductionSnapshot', 'compileGitWorktree', 'assertProductionCodeMatches', 'plan', 'normalizePlan']);
        $root = self::repositoryRoot();
        $branch = self::assertCleanAttachedBranch($root);
        $branchCommit = self::gitStdout($root, ['rev-parse', 'HEAD']);
        $productionCommit = self::resolveCommit($root, $productionRef);
        $baseCommit = self::gitStdout($root, ['merge-base', $branchCommit, $productionCommit]);

        // This validates that the configured target itself is checked out at
        // the exact local production topology.  A completed code descriptor
        // proves bytes, not Git ancestry, so neither check replaces the other.
        self::assertTargetHead($transport, $productionCommit);
        $production = self::readProduction($transport, $productionCommit, $scopeContract);

        $journal = new RefreshRunJournal(self::journalRoot($root));
        $scratch = $journal->scratchPath(self::runId());
        if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
            throw new \RuntimeException("could not create refresh scratch directory '$scratch'");
        }
        try {
            $baseTree = $scratch . '/base';
            $branchTree = $scratch . '/branch';
            $productionTree = $scratch . '/production-code';
            self::git($root, ['worktree', 'add', '--detach', $baseTree, $baseCommit]);
            self::git($root, ['worktree', 'add', '--detach', $branchTree, $branchCommit]);
            self::git($root, ['worktree', 'add', '--detach', $productionTree, $productionCommit]);
            try {
                // Inside this try, not above it: the worktrees are removed by
                // its own finally, and a refusal raised before entering it
                // would leak all three plus the scratch directory the outer
                // finally then cannot rmdir.
                $codeResolve = array_filter([
                    'base' => self::materializeLockedCode($baseTree),
                    'branch' => self::materializeLockedCode($branchTree),
                    'production-code' => self::materializeLockedCode($productionTree),
                ]);
                $base = self::planner('compileGitWorktree', [$baseTree, $baseCommit, 'base']);
                $branchArtifact = self::planner(
                    'compileGitWorktree',
                    [$branchTree, $branchCommit, 'branch', null, $scopeContract !== null]
                );
                // The production-ref compiler is deliberately code-only.  Its
                // repository state must never become P or shadow live truth.
                $productionCode = self::planner('compileGitWorktree', [$productionTree, $productionCommit, 'production-code']);
                self::assertPlannerArray($base, 'base repository compiler');
                self::assertPlannerArray($branchArtifact, 'branch repository compiler');
                self::assertPlannerArray($productionCode, 'production code compiler');
                self::planner('assertProductionCodeMatches', [$production, $productionCode]);

                // Context is an input to plan(), not an after-the-fact
                // annotation: the planner must include it in the canonical
                // plan bytes covered by plan_hash.
                $planContext = [
                    'base_commit' => $baseCommit,
                    'branch_commit' => $branchCommit,
                    'branch_name' => $branch,
                    'production_commit' => $productionCommit,
                    'production_env' => $transport->name(),
                    'production_snapshot_hash' => $production['snapshot_hash'],
                ];
                if ($scopeContract !== null) {
                    $planContext['scope_contract'] = $scopeContract;
                }
                $plan = self::planner('plan', [$base, $production, $branchArtifact, $planContext]);
                $plan = self::planner('normalizePlan', [$plan]);
                if (!is_array($plan) || ($plan['format'] ?? null) !== self::PLAN_FORMAT
                    || !self::isHash($plan['plan_hash'] ?? null)) {
                    throw new \RuntimeException('semantic planner returned an invalid duo-refresh-plan/v1 plan');
                }
                self::assertPlanContext($plan, $planContext);
                $context = $planContext;
                $context['repo_root'] = $root; // host-only, never plan-hashed
                $context['plan_hash'] = $plan['plan_hash'];
                $planPath = $journal->writePlan($plan);
                $fieldDiff = null;
                $fieldBundle = null;
                $fieldDiffPath = null;
                $fieldDiffUnavailable = null;
                if ($includeFieldDiff) {
                    try {
                        // The separate field contract is opt-in. Its validator
                        // is a required planner seam and runs before any public
                        // JSON/journal record can observe the projection.
                        self::requirePlanner(['fieldDiff', 'validateFieldDiff']);
                        $field = self::planner('fieldDiff', [$plan, $base, $productionCode, $branchArtifact]);
                        if (!is_array($field) || !is_array($field['diff'] ?? null) || !is_array($field['bundle'] ?? null)
                            || !self::isHash($field['bundle']['diff_hash'] ?? null)
                            || !hash_equals((string) $plan['plan_hash'], (string) ($field['bundle']['plan_hash'] ?? ''))) {
                            throw new \RuntimeException('field diff did not return a normalized redacted projection');
                        }
                        $fieldDiff = self::planner('validateFieldDiff', [$field['diff']]);
                        if (!is_array($fieldDiff)
                            || ($fieldDiff['format'] ?? null) !== self::FIELD_DIFF_FORMAT
                            || !self::isHash($fieldDiff['diff_hash'] ?? null)
                            || !hash_equals((string) $fieldDiff['diff_hash'], (string) $field['bundle']['diff_hash'])) {
                            throw new \RuntimeException('field diff did not return a normalized redacted projection');
                        }
                        // Materialization must bind the same validated public
                        // projection that was persisted/displayed, never an
                        // adjacent mutable bundle copy supplied by a seam.
                        $field['bundle']['diff'] = $fieldDiff;
                        $field['bundle']['diff_hash'] = $fieldDiff['diff_hash'];
                        $fieldBundle = $field['bundle'];
                        $fieldDiffPath = $journal->writeFieldDiff($fieldDiff);
                    } catch (\Throwable) {
                        // The legacy record resolver remains available for
                        // policy skew/scopes/older planner seams; callers
                        // explicitly requesting fields receive this stable
                        // value-free refusal below.
                        $fieldDiffUnavailable = 'field-level resolution is unavailable for this refresh plan';
                    }
                }
                return [
                    'plan' => $plan,
                    'plan_path' => $planPath,
                    'code_resolve' => $codeResolve,
                    'context' => $context,
                    'field_diff' => $fieldDiff,
                    'field_bundle' => $fieldBundle,
                    'field_diff_path' => $fieldDiffPath,
                    'field_diff_unavailable' => $fieldDiffUnavailable,
                ];
            } finally {
                foreach ([$baseTree, $branchTree, $productionTree] as $tree) {
                    self::removeWorktree($root, $tree, false);
                }
            }
        } finally {
            @rmdir($scratch);
        }
    }

    /** @return array<string,mixed> */
    private static function readProduction(
        EnvironmentDriver $transport,
        string $productionCommit,
        ?array $scopeContract = null
    ): array {
        self::assertTargetHead($transport, $productionCommit);
        // Boot only core + the protected Duo agent. Ordinary WP-CLI plugin,
        // theme, or user-MU bootstrap runs before RefreshExport can open its
        // READ ONLY transaction and can execute arbitrary production DML;
        // that would make a nominal observation mutate the target before our
        // server-enforced boundary even exists. Reuse the proven control
        // bootstrap that shadows user MU code and skips regular plugins and
        // themes while leaving manifest-owned providers available to Duo.
        $wpArgs = ['duo', 'refresh-export', '--repo=' . $transport->repoPath(), '--format=json'];
        if ($scopeContract !== null) {
            $request = [
                'format' => 'duo-scope-request/v1',
                'scope_hash' => (string) ($scopeContract['scope_hash'] ?? ''),
                'selectors' => $scopeContract['selectors'] ?? null,
            ];
            if (!is_array($request['selectors'])
                || preg_match('/^[a-f0-9]{64}$/D', $request['scope_hash']) !== 1) {
                throw new \RuntimeException('refresh scope contract is malformed');
            }
            $wpArgs[] = '--scope-request-b64=' . base64_encode(\Duo\Canon::encode($request));
        }
        $result = $transport->captureWp(CodeDeploy::controlArgs($wpArgs));
        if (($result['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('refresh-export failed for production environment ' . $transport->name()
                . ': ' . self::transportReason($result));
        }
        try {
            $raw = json_decode(trim((string) ($result['stdout'] ?? '')), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('refresh-export returned invalid JSON: ' . $e->getMessage());
        }
        self::assertProductionExportShape($raw);
        $production = self::planner('normalizeProductionSnapshot', [$raw]);
        self::assertProductionExportShape($production);
        if ($scopeContract !== null) {
            $scope = $production['scope'] ?? null;
            if (!is_array($scope)
                || ($scope['format'] ?? null) !== 'duo-refresh-scope/v1'
                || ($scope['out_of_scope'] ?? null) !== 'omitted_not_absent'
                || !hash_equals((string) $scopeContract['scope_hash'], (string) ($scope['scope_hash'] ?? ''))
                || \Duo\Canon::encode($scope['selectors'] ?? null) !== \Duo\Canon::encode($scopeContract['selectors'] ?? null)
                || \Duo\Canon::encode($scope['source'] ?? null) !== \Duo\Canon::encode($scopeContract['source'] ?? null)) {
                throw new \RuntimeException('production scoped refresh export does not match its immutable contract');
            }
        } elseif (array_key_exists('scope', $production)) {
            throw new \RuntimeException('production returned an unexpected scoped refresh export');
        }
        self::assertTargetHead($transport, $productionCommit);
        return $production;
    }

    /** @param mixed $export */
    private static function assertProductionExportShape(mixed $export): void {
        if (!is_array($export) || ($export['format'] ?? null) !== self::EXPORT_FORMAT || !self::isHash($export['snapshot_hash'] ?? null)) {
            throw new \RuntimeException('refresh-export must return duo-refresh-production/v1 with a SHA-256 snapshot_hash');
        }
        foreach (['records', 'media', 'policy', 'repository'] as $key) {
            if (!is_array($export[$key] ?? null)) {
                throw new \RuntimeException("refresh-export '$key' must be an object/array");
            }
        }
        foreach ($export['records'] as $identity => $record) {
            if (!is_string($identity) || !is_array($record)
                || ($record['identity'] ?? null) !== $identity
                || !is_string($record['type'] ?? null) || !is_string($record['path'] ?? null)
                || !self::isHash($record['hash'] ?? null) || !array_key_exists('content', $record)) {
                throw new \RuntimeException('refresh-export has an invalid semantic record');
            }
        }
        foreach ($export['media'] as $key => $media) {
            if (!is_string($key) || !is_array($media) || !self::isHash($media['sha256'] ?? null)
                || !is_string($media['base64'] ?? null)) {
                throw new \RuntimeException('refresh-export has an invalid media entry');
            }
            $bytes = base64_decode($media['base64'], true);
            if ($bytes === false || !hash_equals($media['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException("refresh-export media '$key' does not match its SHA-256");
            }
        }
        $completed = $export['completed_code'] ?? null;
        if ($completed !== null && (!is_array($completed) || !self::isHash($completed['revision'] ?? null)
            || !is_array($completed['descriptor'] ?? null)
            || (isset($completed['descriptor_hash']) && !self::isHash($completed['descriptor_hash'])))) {
            throw new \RuntimeException('refresh-export completed_code must be null or {revision, descriptor object, optional descriptor_hash}');
        }
    }

    private static function assertTargetHead(EnvironmentDriver $transport, string $expected): void {
        $repo = escapeshellarg($transport->repoPath());
        $script = 'git -C ' . $repo . ' rev-parse --verify HEAD^{commit}'
            . ' && git -C ' . $repo . ' status --porcelain=v1 --untracked-files=all'
            // RepositoryCompiler consumes these canonical partitions from
            // the filesystem, including ignored files. An ignored tombstone
            // must not become production deletion authority while Git says
            // the production ref has different bytes.
            . ' && git -C ' . $repo
            . ' ls-files --others --ignored --exclude-standard -- site.duo.json state media code manifests'
            // ...except the component trees the production ref's own lock
            // declares, which are ignored BY DESIGN and are identified bytes
            // rather than unidentified ones (lockedTreePathspecs()). The
            // status walk above is deliberately not narrowed: it never lists
            // an ignored file, so a tracked or untracked change inside a
            // locked tree is a split the repository itself contradicts and
            // still refuses here.
            . self::lockedTreePathspecs($expected);
        $result = $transport->captureRaw($script);
        if (($result['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('cannot verify production target Git HEAD: ' . self::transportReason($result));
        }
        $lines = preg_split('/\r?\n/', trim((string) ($result['stdout'] ?? ''))) ?: [];
        $actual = array_shift($lines) ?? '';
        if (trim(implode("\n", $lines)) !== '') {
            throw new \RuntimeException(
                'production target repository has tracked, untracked, or ignored canonical changes; '
                . 'refusing a refresh-export from bytes not identified by --production-ref'
            );
        }
        if (!self::isGitOid($actual) || !hash_equals($expected, $actual)) {
            throw new \RuntimeException(
                "production target Git HEAD '$actual' does not equal --production-ref '$expected'; refusing to infer topology from a code descriptor"
            );
        }
    }

    /**
     * The `:(exclude)` pathspec suffix that keeps the ignored-files walk above
     * from reporting the code components the production ref itself declares.
     *
     * ## Why an ignored tree can be excluded at all
     *
     * The gate's rule is "no bytes that `--production-ref` does not identify",
     * not "no ignored files". Since DUO-3499 a split repository deliberately
     * keeps each locked component on disk and out of Git
     * (`/code/wp-content/plugins/woocommerce/` in `.gitignore`), and the
     * identification of those bytes is the TRACKED `code/duo-code.lock.json`
     * at that same ref: every entry carries a `tree_sha256` over the whole
     * component subtree, and the compile gate refuses a mismatch as
     * `code_component_digest_mismatch` and an absent tree as
     * `code_component_unresolved`
     * (agent/src/Code/CodeDescriptorCompiler.php:135 lock_diagnostics()). So the
     * lock covers the tree's CONTENTS — a stray file inside a locked component
     * moves that digest — which is why excluding the whole subtree costs this
     * gate nothing, while an ignored file BESIDE a locked tree, an undeclared
     * ignored directory, or a tombstone anywhere else is still unidentified and
     * still refuses, with the message unchanged.
     *
     * Before this, `ls-files --others --ignored -- … code` listed every locked
     * tree on every split repository, so the default init since DUO-3499 could
     * never rehearse or refresh at all: the first `duo rehearse` refused
     * "production target repository has tracked, untracked, or ignored
     * canonical changes" (grind_adapter_walk.sh S1 on a 3-component split).
     *
     * ## Why the lock is read from the LOCAL repository
     *
     * `$expected` is the commit `prepare()` resolved with
     * `resolveCommit($root, $productionRef)`, so the object is in this checkout
     * and `<commit>:code/duo-code.lock.json` is literally "the lock at that
     * ref". No extra target round trip is spent re-reading bytes Git already
     * addresses by oid, and nothing is weakened if the target is somewhere
     * else entirely: this same function then refuses on the HEAD equality
     * below, which is unconditional.
     */
    private static function lockedTreePathspecs(string $expected): string {
        $suffix = '';
        foreach (self::lockedComponentTrees($expected) as $tree) {
            // `literal` is load-bearing, not decoration: a component name is a
            // safe single path segment but may still contain `[`, `*` or `?`
            // (PathSafety::safe_component():77-79 forbids only `..`, `/`,
            // backslashes and control bytes), and a glob-interpreted
            // `:(exclude)` for a component named `wc[1]` silently excludes
            // nothing while reading as though it did.
            $suffix .= ' ' . escapeshellarg(':(exclude,literal)' . $tree);
        }
        return $suffix;
    }

    /**
     * The `code/wp-content/<root>/<component>/` trees `$expected` declares, or
     * `[]` for any repository that does not declare a parseable split.
     *
     * Every early return is the REFUSING direction: no site config, a format-1
     * (fully vendored) declaration, a lock absent at that ref, or a lock that
     * does not parse all yield no exclusions, so the ignored files are reported
     * and the caller refuses exactly as it does today. A repository whose
     * `.gitignore` and lock disagree is DUO-3499's own
     * `code_component_unlocked` — the compile gate's refusal to make, not this
     * one's to guess around.
     *
     * @return list<string>
     */
    private static function lockedComponentTrees(string $expected): array {
        try {
            $root = self::repositoryRoot();
        } catch (\Throwable $t) {
            return [];
        }
        $site = self::run(['git', '-C', $root, 'cat-file', '-p', $expected . ':site.duo.json']);
        if (($site['exit'] ?? 1) !== 0) {
            return [];
        }
        $config = json_decode(trim((string) $site['stdout']), true);
        $code = is_array($config) ? ($config['code'] ?? null) : null;
        // The whole format-2 identity, not just its number: assert_config_v2()
        // (agent/src/Code/CodeDescriptorCompiler.php:431-443) admits exactly
        // one lock path in v1, and a declaration naming another one is not a
        // split this gate knows how to trust.
        if (!is_array($code) || ($code['format'] ?? null) !== 2
            || ($code['lock'] ?? null) !== \Duo\CodeSourceLock::PATH) {
            return [];
        }
        $lock = self::run(['git', '-C', $root, 'cat-file', '-p', $expected . ':' . \Duo\CodeSourceLock::PATH]);
        if (($lock['exit'] ?? 1) !== 0) {
            return [];
        }
        try {
            // parse() runs assert_lock(), so `root` is one of ROOTS and
            // `component` is one safe path segment before either reaches a
            // pathspec — the lock can never name a tree outside code/wp-content.
            $parsed = \Duo\CodeSourceLock::parse(trim((string) $lock['stdout']));
        } catch (\Throwable $t) {
            return [];
        }
        $trees = [];
        foreach ((array) ($parsed['components'] ?? []) as $entry) {
            // gitignore_line() is the spelling that PUT the tree out of Git in
            // the first place (init and `duo code-classify` both write it), so
            // taking it back apart here — leading `/` off, trailing `/` kept —
            // means the excluded path and the ignored path cannot drift.
            $trees[] = substr(
                \Duo\CodeSourceLock::gitignore_line((string) $entry['root'], (string) $entry['component']),
                1
            );
        }
        return $trees;
    }

    /**
     * Put the locked component bytes into a freshly-created compile worktree.
     *
     * ## Why a checkout is not enough
     *
     * Since DUO-3499 a split repository declares `code.format: 2` and keeps each
     * locked component OUT of Git — `code/duo-code.lock.json` is tracked, the
     * bytes are not. `git worktree add --detach` therefore produces a checkout
     * with no `code/wp-content` at all (on the reported repository,
     * `git ls-files code/wp-content` returns 0), and `RepositoryCompiler`
     * correctly refuses it:
     *
     *   [code_source_missing] code/wp-content — site.duo.json opts into code
     *   materialization but code/wp-content is missing
     *
     * That is grind_adapter_walk.sh S1 on a 3-component split (DUO-3523): the
     * host was compiling a checkout it had never populated. Every worktree this
     * class compiles has the same hole, so every one of them is materialized
     * here — base, branch, production-code (prepare():332-334) and the rebase
     * candidate (:187).
     *
     * ## Why this runs in the parent
     *
     * The single compile chokepoint is `RefreshPlan::compileGitWorktreeWorker()`,
     * which would be the tidier home — but it runs in a FRESH PROCESS whose
     * entrypoint reports failure as `fwrite(STDERR, $e->getMessage()); exit(1)`
     * (RefreshPlanCompile.php:28-31). Only the message survives that boundary, so
     * a CommandRefusalException raised there arrives with its reason code
     * stripped and the operator cannot tell a cold cache from a drifted tree
     * from an unreadable lock. Materializing HERE keeps the refusal typed,
     * because CommandRefusalException propagates in-process. (The boundary
     * itself is DUO-3524, filed rather than fixed here.)
     *
     * ## What it does not do
     *
     * Nothing, for a fully vendored repository: `declaredLock()` returns null
     * for `code.format` 1 or a repository with no code half
     * (CodeResolver.php:129-135), so format 1 takes no new path at all. Nothing
     * either for a component already present at its declared `tree_sha256` —
     * `resolve()` compares before it fetches. It never vendors bytes into the
     * commit: the resolver writes only under `code/wp-content`, the worktree is
     * disposable, and the lock still governs what those bytes must hash to.
     *
     * Resolution is ONLINE, exactly as `duo code-resolve` and the deploy
     * code-resolve phase are: the components are digest-pinned by the lock and
     * every archive is verified against `archive_sha256` and `tree_sha256` on
     * unpack, so reaching the host cache and then wp.org adds no trust. A cold
     * cache with no network is named by the resolver's own typed
     * `code_resolve_*` refusal rather than by a second offline mode invented
     * here.
     */
    public static function materializeLockedCode(string $worktree): ?array {
        $lock = CodeResolver::declaredLock($worktree);
        if ($lock === null) {
            return null;
        }
        $resolver = new CodeResolver(new WpOrgReleases(WpOrgReleases::defaultCacheDir()));

        // The rows are RETURNED, never printed: this file has no `echo` in it
        // at all and is a library that hands structured results to a command
        // that renders them. The refresh/rebase/rehearse callers pass them to
        // CodeResolveCommand's own renderer, so a split refresh reports the
        // same `RESOLVED/UNCHANGED` rows and the same `N materialized, M
        // unchanged` summary as `duo code-resolve` and the deploy phase — one
        // vocabulary for one piece of work.
        return ['lock' => $lock['path'], 'rows' => $resolver->resolve($worktree, $lock['components'], false)];
    }

    private static function rebaseCodeOnly(string $worktree, string $production, string $base, string $runDir): void {
        $attributes = $runDir . '/state-merge.attributes';
        $bytes = "state/** merge=duo-refresh-ours\nmedia/** merge=duo-refresh-ours\n";
        if (file_put_contents($attributes, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException('could not write temporary state merge attributes');
        }
        // In a rebase, %A is the new production-side version.  `true` leaves
        // it intact for any raw state conflict; the planner later replaces the
        // whole state/media result semantically.  Code paths retain native Git
        // conflict behavior and stop here for human recovery.
        self::git($worktree, [
            '-c', 'core.attributesFile=' . $attributes,
            '-c', 'merge.duo-refresh-ours.name=Duo refresh state placeholder',
            '-c', 'merge.duo-refresh-ours.driver=true',
            'rebase', '--onto', $production, $base,
        ]);
    }

    private static function assertOnlyStatePathsChanged(string $worktree): void {
        // Disable rename folding so each canonical path is checked directly;
        // porcelain's `old -> new` display is otherwise ambiguous to a path
        // boundary even when both ends are under state/.
        // NUL mode keeps status columns and paths byte-exact: no first-column
        // trimming and no Git C-quoting of Unicode, whitespace, or newlines.
        $paths = array_filter(explode("\0", self::gitRawStdout($worktree, [
            'status', '--porcelain=v1', '-z', '--untracked-files=all', '--no-renames',
        ])), static fn(string $row): bool => $row !== '');
        foreach ($paths as $line) {
            $path = trim(substr($line, 3));
            if ($path === '' || (!str_starts_with($path, 'state/') && !str_starts_with($path, 'media/'))) {
                throw new \RuntimeException("semantic materializer changed non-state path '$path'; candidate worktree retained");
            }
        }
    }

    private static function commitMaterializedState(string $worktree, string $planHash): void {
        // We already checked both unstaged and post-validation paths. Stage
        // globally so an absent optional media/ directory is not a pathspec
        // error, then repeat the boundary on the staged set.
        self::git($worktree, ['add', '-A']);
        self::assertNoUnmerged($worktree);
        $status = self::gitRawStdout($worktree, ['diff', '--cached', '--name-only', '-z']);
        foreach (array_filter(explode("\0", $status), static fn(string $path): bool => $path !== '') as $path) {
            if (!str_starts_with($path, 'state/') && !str_starts_with($path, 'media/')) {
                throw new \RuntimeException("semantic materializer staged non-state path '$path'; no branch was created");
            }
        }
        if ($status !== '') {
            self::git($worktree, ['commit', '-m', 'duo refresh rebase ' . $planHash]);
        }
    }

    private static function assertNoUnmerged(string $worktree): void {
        if (trim(self::gitStdout($worktree, ['diff', '--name-only', '--diff-filter=U'])) !== '') {
            throw new \RuntimeException('unresolved Git conflict remains in candidate worktree; no branch was created');
        }
    }

    /** @param array<string,mixed> $context */
    private static function assertOriginalStillUsable(string $root, array $context): void {
        if (self::gitStdout($root, ['rev-parse', 'HEAD']) !== $context['branch_commit']
            || self::gitStdout($root, ['symbolic-ref', '--short', 'HEAD']) !== $context['branch_name']) {
            throw new \RuntimeException('source checkout changed while refresh ran; candidate retained and no branch ref was created');
        }
        self::assertCleanAttachedBranch($root);
    }

    private static function createNewRef(string $root, string $branch, string $head): void {
        self::assertNewBranch($root, $branch);
        if (!self::isGitOid($head)) {
            throw new \RuntimeException('candidate Git object id is invalid; no branch ref was created');
        }
        self::git($root, ['update-ref', 'refs/heads/' . $branch, $head, str_repeat('0', strlen($head))]);
    }

    private static function assertNewBranch(string $root, string $branch): void {
        if ($branch === '' || str_starts_with($branch, '-') || str_contains($branch, '\\')) {
            throw new \RuntimeException('--new-branch must be a new, valid local branch name');
        }
        self::git($root, ['check-ref-format', '--branch', $branch]);
        $exists = self::run(['git', '-C', $root, 'show-ref', '--verify', '--quiet', 'refs/heads/' . $branch]);
        if ($exists['exit'] === 0) {
            throw new \RuntimeException("--new-branch '$branch' already exists; refusing to replace any ref");
        }
        if ($exists['exit'] !== 1) {
            throw new \RuntimeException("could not determine whether branch '$branch' exists: " . self::reason($exists));
        }
    }

    private static function resolveCommit(string $root, string $ref): string {
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, "\0")) {
            throw new \RuntimeException('--production-ref must name a reachable Git commit');
        }
        return self::gitStdout($root, ['rev-parse', '--verify', $ref . '^{commit}']);
    }

    private static function repositoryRoot(): string {
        $root = self::gitStdout(getcwd() ?: '.', ['rev-parse', '--show-toplevel']);
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException('refresh/rebase must run inside a Git worktree');
        }
        return $root;
    }

    /** Keep host operational state inside Git metadata, never in the tracked checkout. */
    private static function journalRoot(string $root): string {
        return self::gitStdout($root, ['rev-parse', '--path-format=absolute', '--git-common-dir']) . '/duo-refresh';
    }

    private static function assertCleanAttachedBranch(string $root): string {
        $branch = self::gitStdout($root, ['symbolic-ref', '--short', 'HEAD']);
        if ($branch === '') {
            throw new \RuntimeException('refresh/rebase refuses a detached source HEAD; switch to the branch that must remain usable');
        }
        if (trim(self::gitStdout($root, ['status', '--porcelain=v1', '--untracked-files=all'])) !== '') {
            throw new \RuntimeException('refresh/rebase requires a clean source checkout; commit/stash work before creating an isolated candidate');
        }
        return $branch;
    }

    /** @return mixed */
    private static function planner(string $method, array $args): mixed {
        $class = __NAMESPACE__ . '\\RefreshPlan';
        if (!class_exists($class) || !method_exists($class, $method)) {
            throw new \RuntimeException("refresh semantic planner is unavailable: $class::$method() is required; no candidate branch was created");
        }
        return $class::$method(...$args);
    }

    /** @param list<string> $methods */
    private static function requirePlanner(array $methods): void {
        $class = __NAMESPACE__ . '\\RefreshPlan';
        foreach ($methods as $method) {
            if (!class_exists($class) || !method_exists($class, $method)) {
                throw new \RuntimeException("refresh semantic planner is unavailable: $class::$method() is required; no candidate branch was created");
            }
        }
    }

    /**
     * Refuse to materialize from a plan that carries no production authority.
     *
     * `MergeCheck` assembles B/P/W entirely from Git refs and marks the
     * result by putting `advisory: true` and `production_source: 'git-ref'`
     * into the plan CONTEXT, which `RefreshPlan::plan()` folds into the bytes
     * `plan_hash` covers (RefreshPlan.php:456-464 `$safeContext`) — so an
     * advisory plan cannot be relabelled without changing its own hash, and
     * an advisory `plan_hash` can never collide with a refresh one.
     *
     * This is belt-and-braces, deliberately. The structural fact that already
     * makes the mistake impossible is that a plan never round-trips from
     * disk: `RefreshRunJournal` has `writePlan()` (:1076) and no reader at
     * all, so the only plan `rebase()` can materialize is the one its own
     * `prepare()` just built from a live `refresh-export`. This guard states
     * that invariant positively at the two entries that write, so a future
     * `--continue`/`--from-plan` path cannot acquire the hole by omission.
     */
    public static function assertAuthorizingPlan(array $plan): void {
        $context = $plan['context'] ?? null;
        if (!is_array($context)) {
            return;
        }
        if (($context['advisory'] ?? null) === true || array_key_exists('production_source', $context)) {
            throw new \RuntimeException(
                'a merge-check plan is advisory and carries no production authority; '
                . 'run duo rebase <production-env> --production-ref=<ref> to materialize'
            );
        }
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context */
    private static function assertPlanContext(array $plan, array $context): void {
        $actual = $plan['context'] ?? null;
        if (!is_array($actual)) {
            throw new \RuntimeException('semantic planner plan is missing its hash-bound context');
        }
        foreach ($context as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                throw new \RuntimeException("semantic planner plan context does not bind '$key'");
            }
        }
    }

    /** @param mixed $value */
    private static function assertPlannerArray(mixed $value, string $what): void {
        if (!is_array($value) || !is_string($value['format'] ?? null)) {
            throw new \RuntimeException("$what did not return a normalized artifact");
        }
    }

    /**
     * Public for its second caller, `MergeCheck::run()` (cli/src/Refresh/MergeCheck.php).
     *
     * This and the three helpers below are the transport-free half of this
     * class: they run `git` in a child process and nothing else — no
     * environment, no registry, no target. `merge-check` needs exactly that
     * half and nothing above it, so promoting these four is the whole seam
     * rather than a second copy of them living beside this file (AGENTS.md
     * rule 9). Behaviour is unchanged and no diagnostic string moves; the
     * only thing this edit changes is who may call them.
     */
    public static function removeWorktree(string $root, string $path, bool $prune = true): void {
        $result = self::run(['git', '-C', $root, 'worktree', 'remove', '--force', $path]);
        if ($result['exit'] !== 0 && (is_dir($path) || is_file($path . '/.git'))) {
            throw new \RuntimeException("could not remove refresh worktree '$path': " . self::reason($result));
        }
        if ($prune) {
            self::git($root, ['worktree', 'prune']);
        }
    }

    /** Transport-free; see removeWorktree(). @return array{exit:int,stdout:string,stderr:string} */
    public static function run(array $command, ?string $cwd = null): array {
        $pipes = [];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'could not start git'];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Transport-free; see removeWorktree(). */
    public static function git(string $cwd, array $args): void {
        $result = self::run(array_merge(['git', '-C', $cwd], $args));
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . self::reason($result));
        }
    }

    /** Transport-free; see removeWorktree(). */
    public static function gitStdout(string $cwd, array $args): string {
        $result = self::run(array_merge(['git', '-C', $cwd], $args));
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . self::reason($result));
        }
        return trim($result['stdout']);
    }

    private static function gitRawStdout(string $cwd, array $args): string {
        $result = self::run(array_merge(['git', '-C', $cwd], $args));
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . self::reason($result));
        }
        return $result['stdout'];
    }

    private static function transportReason(array $result): string {
        $all = trim((string) ($result['stderr'] ?? ''));
        $out = trim((string) ($result['stdout'] ?? ''));
        return $all !== '' ? $all : ($out !== '' ? $out : 'no diagnostic');
    }

    private static function reason(array $result): string {
        return self::transportReason($result);
    }

    private static function isHash(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function isGitOid(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) === 1;
    }

    /**
     * `ours` means the declared branch desired record, `theirs` means current
     * production.  The semantic planner owns stable-ID validation and refuses
     * a manual/unresolved conflict; this shell only makes the user's choice
     * explicit and journal-bound.
     *
     * @return array{strategy:string,records:array<string,string>}
     */
    private static function normalizeResolution(array $resolution): array {
        $strategy = $resolution['strategy'] ?? 'manual';
        if (!is_string($strategy) || !in_array($strategy, ['manual', 'ours', 'theirs'], true)) {
            throw new \RuntimeException('--strategy must be manual, ours, or theirs');
        }
        $records = $resolution['records'] ?? [];
        if (!is_array($records) || ($records !== [] && array_is_list($records))) {
            throw new \RuntimeException('--resolve entries must be keyed by stable record id');
        }
        ksort($records, SORT_STRING);
        foreach ($records as $id => $choice) {
            if (!is_string($id) || $id === '' || strlen($id) > 512 || str_contains($id, "\0")
                || !is_string($choice) || !in_array($choice, ['ours', 'theirs'], true)) {
                throw new \RuntimeException('--resolve must use <stable-id>=ours|theirs');
            }
        }
        return ['strategy' => $strategy, 'records' => $records];
    }

    private static function runId(): string {
        return gmdate('Ymd-His') . '-' . bin2hex(random_bytes(12));
    }
}

/**
 * Append-only local evidence for a host-only refresh/rebase run.  `plan` and
 * `run.json` are immutable; transition evidence is an ordered set of immutable
 * event records.  This makes interrupted candidate worktrees recoverable
 * without ever recording mutable state in the source branch.
 */
final class RefreshRunJournal {
    private string $base;

    public function __construct(string $base) {
        $this->base = rtrim($base, '/');
        foreach ([$this->base, $this->base . '/plans', $this->base . '/field-diffs', $this->base . '/runs', $this->base . '/worktrees', $this->base . '/scratch'] as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("could not create refresh journal directory '$path'");
            }
        }
    }

    public function writePlan(array $plan): string {
        $hash = $plan['plan_hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \RuntimeException('cannot journal a refresh plan without a SHA-256 plan_hash');
        }
        return $this->writeImmutable($this->base . '/plans/' . $hash . '.json', $plan);
    }

    public function writeFieldDiff(array $diff): string {
        $hash = $diff['diff_hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || ($diff['format'] ?? null) !== 'duo-refresh-field-diff/v1') {
            throw new \RuntimeException('cannot journal an invalid redacted field diff');
        }
        // Do not trust a superficially-shaped result from a planner seam.
        // The immutable public journal is only allowed to carry the same
        // closed/hash-verified projection the TTY and JSON renderer received.
        $validator = __NAMESPACE__ . '\\RefreshFieldDiff';
        if (!class_exists($validator) || !method_exists($validator, 'validateDiff')) {
            throw new \RuntimeException('cannot journal an unavailable redacted field diff validator');
        }
        try {
            $validator::validateDiff($diff);
        } catch (\Throwable) {
            throw new \RuntimeException('cannot journal an invalid redacted field diff');
        }
        return $this->writeImmutable($this->base . '/field-diffs/' . $hash . '.json', $diff);
    }

    public function start(string $runId, array $run): void {
        $dir = $this->runDir($runId);
        if (is_dir($dir)) {
            throw new \RuntimeException("refresh run '$runId' already exists");
        }
        if (!mkdir($dir . '/events', 0700, true) && !is_dir($dir . '/events')) {
            throw new \RuntimeException("could not create refresh run '$runId'");
        }
        $this->writeImmutable($dir . '/run.json', $run);
        $this->append($runId, 'prepared', ['plan_hash' => $run['plan_hash'] ?? null]);
    }

    /** @return array<string,mixed> */
    public function read(string $runId): array {
        $path = $this->runDir($runId) . '/run.json';
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("refresh run '$runId' does not exist");
        }
        try {
            $run = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("refresh run '$runId' is malformed: " . $e->getMessage());
        }
        if (!is_array($run)) {
            throw new \RuntimeException("refresh run '$runId' is malformed");
        }
        return $run;
    }

    public function append(string $runId, string $event, array $data): void {
        $dir = $this->runDir($runId);
        if (!is_dir($dir . '/events')) {
            throw new \RuntimeException("refresh run '$runId' has no event journal");
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("could not lock refresh run '$runId'");
        }
        try {
            $events = glob($dir . '/events/*.json') ?: [];
            sort($events, SORT_STRING);
            $seq = count($events) + 1;
            $record = ['data' => $data, 'event' => $event, 'format' => 'duo-refresh-event/v1', 'sequence' => $seq, 'timestamp' => gmdate('c')];
            $this->writeImmutable(sprintf('%s/events/%04d-%s.json', $dir, $seq, preg_replace('/[^a-z0-9-]/', '-', $event)), $record);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function runDir(string $runId): string {
        $this->assertRunId($runId);
        return $this->base . '/runs/' . $runId;
    }

    public function worktreePath(string $runId): string {
        $this->assertRunId($runId);
        return $this->base . '/worktrees/' . $runId;
    }

    public function scratchPath(string $id): string {
        $this->assertRunId($id);
        return $this->base . '/scratch/' . $id;
    }

    private function assertRunId(string $runId): void {
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $runId) !== 1) {
            throw new \RuntimeException('invalid refresh run id');
        }
    }

    private function writeImmutable(string $path, array $record): string {
        $bytes = self::encode($record) . "\n";
        if (is_file($path)) {
            $current = file_get_contents($path);
            if ($current === $bytes) {
                return $path;
            }
            throw new \RuntimeException("immutable refresh journal record already differs at '$path'");
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $written = file_put_contents($tmp, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            @unlink($tmp);
            throw new \RuntimeException("could not write refresh journal record '$path'");
        }
        @chmod($tmp, 0600);
        // link(2) is create-only, unlike rename(2), which may replace a
        // concurrent writer's record. Both files are in the same Git common
        // directory, so a hard link gives an atomic no-clobber publication.
        if (@link($tmp, $path)) {
            @unlink($tmp);
            return $path;
        }
        $current = is_file($path) ? file_get_contents($path) : false;
        @unlink($tmp);
        if ($current === $bytes) {
            return $path;
        }
        throw new \RuntimeException("could not publish immutable refresh journal record '$path'");
    }

    private static function encode(mixed $value): string {
        return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function canonicalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        $out = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $out[(string) $key] = self::canonicalize($value[$key]);
        }
        return $out;
    }
}
