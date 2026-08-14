<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-import-type CatalogAggregate from Catalog
 * @phpstan-import-type Suite from Catalog
 * @phpstan-import-type SelectionDocument from Selection
 * @phpstan-import-type ShardContext from ShardPlan
 * @phpstan-import-type HarnessContext from HarnessApproval
 * @phpstan-type ArtifactResult array{path:string,sha256:string}
 * @phpstan-type SuiteResult array{
 *     id:string,
 *     state:string,
 *     exit_code:?int,
 *     timed_out:bool,
 *     signal:?int,
 *     cleanup:string,
 *     duration_ms:int,
 *     log_path:?string,
 *     log_sha256:?string,
 *     artifacts:list<ArtifactResult>,
 *     message:?string
 * }
 * @phpstan-type CatalogScope array{kind:string,owner:?string}
 */
final class Runner
{
    private const OUTPUT_BUFFER_LIMIT = 65536;
    private const OUTPUT_BUFFER_RETAIN = 4096;

    /** @var CatalogAggregate */
    private readonly array $catalog;

    /** @var CatalogScope */
    private readonly array $catalogScope;

    /** @var SelectionDocument|null */
    private readonly ?array $selection;

    /** @var ShardContext|null */
    private readonly ?array $shard;

    /** @var HarnessContext|null */
    private readonly ?array $harness;

    private readonly string $resultPath;
    private readonly ?string $junitPath;
    private readonly ?string $tapPath;
    private readonly ?string $requestedRunId;

    /** @var list<string> */
    private readonly array $ownedOutputTrees;

    /** @var list<string> */
    private readonly array $ownedOutputPaths;
    private ?int $activeProcessGroup = null;
    private bool $interrupted = false;
    private ?int $interruptSignal = null;
    private bool $finalizing = false;

    /** @var array<string,string> receipt key => sha256 digest */
    private array $toolchainBindings = [];

    /** @var array<string,string> receipt key => absolute path */
    private array $toolchainPaths = [];

    /** @var array<string,string> suite id => sha256 digest */
    private array $suiteExecutableBindings = [];

    private ?string $setsidPath = null;

    /**
     * @param CatalogAggregate $catalog
     * @param SelectionDocument|null $selection
     * @param ShardContext|null $shard
     * @param HarnessContext|null $harness
     * @param list<string> $ownedOutputTrees
     * @param list<string> $ownedOutputPaths
     */
    public function __construct(
        private readonly string $root,
        array $catalog,
        string $resultPath,
        ?string $partialOwner = null,
        ?array $selection = null,
        ?array $shard = null,
        ?string $junitPath = null,
        ?string $tapPath = null,
        ?array $harness = null,
        ?string $runId = null,
        array $ownedOutputTrees = [],
        array $ownedOutputPaths = [],
    ) {
        $this->catalog = $catalog;
        $this->catalogScope = $partialOwner === null
            ? ['kind' => 'complete', 'owner' => null]
            : ['kind' => 'partial_owner', 'owner' => $partialOwner];
        $this->resultPath = self::normalizeArtifactPath($root, $resultPath);
        $this->selection = $selection;
        $this->shard = $shard;
        $this->harness = $harness;
        if ($runId !== null && preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}-[a-f0-9]{8}$/D', $runId) !== 1) {
            throw new CatalogException('runner run ID is malformed');
        }
        $this->requestedRunId = $runId;
        $this->ownedOutputTrees = $this->validateParallelOutputs(
            $ownedOutputTrees,
            '~^artifacts/test-results/runs/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}-[a-f0-9]{8}$~D',
            'tree',
        );
        $this->ownedOutputPaths = $this->validateParallelOutputs(
            $ownedOutputPaths,
            '~^artifacts/(?:[A-Za-z0-9._-]+/)+shards/shard-[0-9]+\.json$~D',
            'path',
        );
        $this->junitPath = $junitPath === null ? null : self::normalizeArtifactPath($root, $junitPath);
        $this->tapPath = $tapPath === null ? null : self::normalizeArtifactPath($root, $tapPath);
        if ($this->junitPath !== null && $this->junitPath === $this->tapPath) {
            throw new CatalogException('JUnit and TAP reports require distinct output paths');
        }
        register_shutdown_function(function (): void {
            if ($this->activeProcessGroup !== null) {
                $this->terminateGroup($this->activeProcessGroup);
            }
        });
    }

    /** @param list<string> $suiteIds */
    public function run(array $suiteIds, ?string $profileId): int
    {
        $previousHandlers = $this->installSignalHandlers();
        try {
            return $this->runSelected($suiteIds, $profileId);
        } finally {
            $this->restoreSignalHandlers($previousHandlers);
        }
    }

    /** @param list<string> $suiteIds */
    private function runSelected(array $suiteIds, ?string $profileId): int
    {
        $suiteMap = [];
        foreach ($this->catalog['suites'] as $suite) {
            $suiteMap[$suite['id']] = $suite;
        }
        $profile = null;
        if ($profileId !== null) {
            foreach ($this->catalog['profiles'] as $candidate) {
                if ($candidate['id'] === $profileId) {
                    $profile = $candidate;
                    break;
                }
            }
            if ($profile === null) {
                throw new CatalogException("unknown profile: $profileId");
            }
            $suiteIds = $profile['suite_ids'];
            if (!in_array($this->resultPath, $profile['expected_outputs'], true)) {
                throw new CatalogException("result path is not declared by profile $profileId");
            }
        }
        $notApplicable = $this->selection !== null && $this->selection['state'] === 'not_applicable';
        if ($suiteIds === [] && !$notApplicable) {
            throw new CatalogException('runner selection is empty');
        }
        foreach ($suiteIds as $suiteId) {
            if (!isset($suiteMap[$suiteId])) {
                throw new CatalogException("unknown suite: $suiteId");
            }
        }
        /** @var list<Suite> $selectedSuites */
        $selectedSuites = array_map(static fn(string $id): array => $suiteMap[$id], $suiteIds);
        $this->bindToolchain($selectedSuites);
        $platformContract = $this->platformContract();

        $candidateSha = trim($this->capture(['git', 'rev-parse', 'HEAD'], $this->root));
        if (preg_match('/^[a-f0-9]{40}$/D', $candidateSha) !== 1) {
            throw new CatalogException('candidate commit is not a full SHA');
        }
        if ($this->selection !== null
            && $this->selection['selector_version'] === 'changed-paths/v1'
            && $this->selection['head_sha'] !== $candidateSha) {
            throw new CatalogException('changed selection head does not match the candidate commit');
        }
        if ($this->shard !== null && $this->shard['candidate_sha'] !== $candidateSha) {
            throw new CatalogException('shard plan candidate does not match the current commit');
        }
        $dirtyBefore = $this->capture(['git', 'status', '--porcelain=v1', '-z'], $this->root);
        $startedWall = gmdate('Y-m-d\TH:i:s\Z');
        $started = hrtime(true);
        $runId = $this->requestedRunId
            ?? gmdate('Ymd\THis\Z') . '-' . substr($candidateSha, 0, 12) . '-' . bin2hex(random_bytes(4));
        $logRootRelative = 'artifacts/test-results/runs/' . $runId;
        $logRoot = $this->absoluteArtifactPath($logRootRelative);
        if (!$notApplicable) {
            if (!mkdir($logRoot, 0700, true) && !is_dir($logRoot)) {
                throw new CatalogException('cannot create result log directory');
            }
            chmod($logRoot, 0700);
        }

        /** @var list<SuiteResult> $results */
        $results = $notApplicable
            ? [$this->result('selector.changed-paths', 'not_applicable', $this->selection['reason'])]
            : [];
        foreach ($suiteIds as $index => $suiteId) {
            if ($this->interrupted) {
                foreach (array_slice($suiteIds, $index) as $unrunId) {
                    $results[] = $this->infrastructureResult($unrunId, 'suite was not run because the runner was interrupted');
                }
                break;
            }
            $results[] = $this->runSuite($suiteMap[$suiteId], $logRoot, $logRootRelative);
            $changedBinding = $this->changedToolchainBinding();
            if ($changedBinding !== null) {
                $results[] = $this->infrastructureResult(
                    'runner.toolchain-integrity',
                    "bound execution input changed during suite $suiteId: $changedBinding",
                );
                foreach (array_slice($suiteIds, $index + 1) as $unrunId) {
                    $results[] = $this->infrastructureResult($unrunId, 'suite was not run after execution-input tampering');
                }
                break;
            }
        }

        $profileArtifacts = [];
        if ($profile !== null) {
            foreach ($profile['expected_outputs'] as $expectedOutput) {
                if ($expectedOutput === $this->resultPath) {
                    continue;
                }
                $artifact = $this->artifactResult($this->root, $expectedOutput);
                if ($artifact === null) {
                    $results[] = $this->failureResult(
                        'runner.profile-artifacts',
                        "profile output was not produced: $expectedOutput",
                    );
                } else {
                    $profileArtifacts[] = $artifact;
                }
            }
        }

        $dirtyAfter = $this->capture(['git', 'status', '--porcelain=v1', '-z'], $this->root);
        if ($dirtyAfter !== $dirtyBefore) {
            $results[] = $this->infrastructureResult(
                'runner.workspace-integrity',
                'Git-visible workspace state changed during the catalog run',
            );
        }
        // Enter the terminal commit boundary before the final interruption
        // read. The signal handler ignores later signals, while any signal
        // delivered before this assignment is already visible below.
        $this->finalizing = true;
        if ($this->interrupted) {
            $results[] = $this->infrastructureResult(
                'runner.interruption',
                'runner was interrupted before terminal receipt finalization',
            );
        }
        $aggregateState = $this->aggregateState($results);
        $reportArtifacts = [];
        foreach ([
            [$this->junitPath, $this->junitPath === null ? null : Reports::junit($results)],
            [$this->tapPath, $this->tapPath === null ? null : Reports::tap($results)],
        ] as [$reportPath, $reportBytes]) {
            if (!is_string($reportPath) || !is_string($reportBytes)) {
                continue;
            }
            $this->publish($reportPath, $reportBytes);
            $artifact = $this->artifactResult($this->root, $reportPath);
            if ($artifact === null) {
                $results[] = $this->infrastructureResult('runner.reports', 'cannot retain structured report');
                $aggregateState = 'infra_error';
            } else {
                $reportArtifacts[] = $artifact;
            }
        }
        $receipt = [
            'format' => 'duo-test-run-receipt/v1',
            'phase' => 'complete',
            'authority' => $this->authority($profileId),
            'state' => $aggregateState,
            'message' => $this->interrupted ? 'runner interrupted' : null,
            'candidate_sha' => $candidateSha,
            'candidate_dirty' => $dirtyBefore !== '',
            'catalog_scope' => $this->catalogScope,
            'catalog_sha256' => 'sha256:' . hash(
                'sha256',
                (new Catalog($this->root))->encode($this->catalog),
            ),
            'catalog_schema_sha256' => $this->fileDigest(__DIR__ . '/schema.json'),
            'receipt_schema_sha256' => $this->fileDigest(__DIR__ . '/run-receipt.schema.json'),
            'invocation_schema_sha256' => $this->fileDigest(__DIR__ . '/invocation.schema.json'),
            'selection_schema_sha256' => $this->fileDigest(__DIR__ . '/selection.schema.json'),
            'profile_id' => $profileId,
            'profile_sha256' => $profile === null ? null : 'sha256:' . hash('sha256', $this->canonical($profile)),
            'selection' => $this->selectionSummary(),
            'selection_sha256' => $this->selection === null
                ? null
                : 'sha256:' . hash('sha256', $this->canonical($this->selection)),
            'shard' => $this->shard,
            'shard_plan_schema_sha256' => $this->fileDigest(__DIR__ . '/shard-plan.schema.json'),
            'selected_suite_ids' => $suiteIds,
            'selected_set_sha256' => 'sha256:' . hash('sha256', $this->canonical($suiteIds)),
            'execution_plan' => array_map(fn(array $suite): array => $this->executionPlan($suite), $selectedSuites),
            'runner_sha256' => $this->fileDigest(__FILE__),
            'dependency_lock_sha256' => $this->fileDigest($this->root . '/composer.lock'),
            'toolchain' => $this->toolchainBindings,
            'image_digest' => $this->harness['image_digest'] ?? null,
            'harness' => $this->harnessSummary(),
            'platform_contract' => $platformContract,
            'started_at' => $startedWall,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'interrupt_signal' => $this->interruptSignal,
            'profile_artifacts' => $profileArtifacts,
            'report_artifacts' => $reportArtifacts,
            'results' => $results,
        ];
        $this->publish($this->resultPath, $this->canonical($receipt) . "\n");
        printf(
            "runner: %s (%d/%d suites materialized); result %s\n",
            $aggregateState,
            count($results),
            count($suiteIds),
            $this->resultPath,
        );
        if ($this->interruptSignal !== null) {
            return 128 + $this->interruptSignal;
        }
        return in_array($aggregateState, ['pass', 'not_applicable'], true) ? 0 : 1;
    }

    /**
     * @param Suite $suite
     * @return SuiteResult
     */
    private function runSuite(array $suite, string $logRoot, string $logRootRelative): array
    {
        $id = $suite['id'];
        if ($this->setsidPath === null || !function_exists('posix_kill')) {
            return $this->infrastructureResult($id, 'setsid and posix_kill are required for process-group cleanup');
        }
        if ($suite['environment_class'] !== 'offline') {
            $mismatch = $this->harnessMismatch($suite);
            if ($mismatch !== null) {
                return $this->infrastructureResult($id, $mismatch);
            }
            foreach ($suite['required_services'] as $service) {
                if ($this->findTool($service) === null) {
                    return $this->infrastructureResult($id, "approved required service is unavailable: $service");
                }
            }
        } elseif ($suite['required_services'] !== []) {
            return $this->infrastructureResult($id, 'offline suite cannot depend on a provisioned service');
        }

        $controlRoot = sys_get_temp_dir() . '/duo-test-' . preg_replace('/[^a-z0-9.-]+/', '-', $id) . '-' . bin2hex(random_bytes(6));
        if (!mkdir($controlRoot, 0700)) {
            return $this->infrastructureResult($id, 'cannot create runner control directory');
        }
        $workspace = $this->root;
        $worktreeCreated = false;
        $lock = null;
        $cleanupMessage = null;
        if ($suite['workspace_mode'] === 'isolated_copy') {
            $workspace = $controlRoot . '/workspace';
            try {
                $this->capture(['git', 'worktree', 'add', '--quiet', '--detach', $workspace, 'HEAD'], $this->root);
                $worktreeCreated = true;
            } catch (CatalogException $exception) {
                $this->removeTree($controlRoot);
                return $this->infrastructureResult($id, 'cannot create isolated checkout: ' . $exception->getMessage());
            }
        } elseif ($suite['workspace_mode'] === 'exclusive') {
            $lockPath = $this->absoluteArtifactPath('artifacts/test-results/locks/exclusive-workspace.lock');
            if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0700, true) && !is_dir(dirname($lockPath))) {
                $this->removeTree($controlRoot);
                return $this->infrastructureResult($id, 'cannot create exclusive lock directory');
            }
            $lock = fopen($lockPath, 'c');
            if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                $this->removeTree($controlRoot);
                return $this->infrastructureResult($id, 'exclusive workspace lock is held');
            }
        }

        $enforceWorkspaceIntegrity = $suite['workspace_mode'] === 'read_only';
        $runnerOwnedOutputTrees = array_merge($this->inheritedRunnerOutputTrees(), $this->ownedOutputTrees);
        $runnerOwnedOutputTrees[] = $logRootRelative;
        $runnerOwnedOutputTrees = array_values(array_unique($runnerOwnedOutputTrees));
        $fingerprintExcludedTrees = array_merge(['.git'], $runnerOwnedOutputTrees);
        $fingerprintExcludedExactPaths = array_merge($suite['expected_outputs'], $this->ownedOutputPaths);
        foreach ($suite['expected_outputs'] as $expectedOutput) {
            $ancestor = dirname($expectedOutput);
            while ($ancestor !== '.' && $ancestor !== '') {
                if (!file_exists($workspace . '/' . $ancestor) && !is_link($workspace . '/' . $ancestor)) {
                    $fingerprintExcludedExactPaths[] = $ancestor;
                }
                $ancestor = dirname($ancestor);
            }
        }
        $fingerprintExcludedExactPaths = array_values(array_unique($fingerprintExcludedExactPaths));
        $workspaceBefore = $enforceWorkspaceIntegrity
            ? $this->workspaceFingerprint($workspace, $fingerprintExcludedTrees, $fingerprintExcludedExactPaths)
            : [];
        foreach ($suite['expected_outputs'] as $expectedOutput) {
            self::normalizeArtifactPath($workspace, $expectedOutput);
            $staleOutput = $workspace . '/' . $expectedOutput;
            if ((file_exists($staleOutput) || is_link($staleOutput))
                && (!is_file($staleOutput) || is_link($staleOutput) || !unlink($staleOutput))) {
                $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
                return $this->infrastructureResult($id, "cannot clear stale declared output: $expectedOutput");
            }
        }
        $temporary = $controlRoot . '/tmp';
        $home = $controlRoot . '/home';
        if (!mkdir($temporary, 0700) || !mkdir($home, 0700)) {
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'cannot create isolated environment directories');
        }
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $home,
            'TMPDIR' => $temporary,
            'COMPOSER_HOME' => $home . '/composer',
            'XDG_CACHE_HOME' => $home . '/cache',
            'LC_ALL' => 'C',
            'LANG' => 'C',
            'TZ' => 'UTC',
            'DUO_RUNNER_OWNED_OUTPUT_TREES' => $this->canonical($runnerOwnedOutputTrees),
        ];
        if ($suite['temporary_directory'] === 'unique') {
            $environment['DUO_TEST_TMPDIR'] = $temporary;
        }
        if ($suite['environment_class'] !== 'offline' && $this->harness !== null) {
            foreach ($this->harness['environment'] as $name => $value) {
                $environment[$name] = $value;
            }
        }
        $redactions = array_values(array_filter([
            $this->root,
            $workspace,
            $controlRoot,
            is_string(getenv('HOME')) ? getenv('HOME') : null,
        ], static fn(mixed $value): bool => is_string($value) && $value !== ''));
        if ($suite['environment_class'] !== 'offline' && $this->harness !== null) {
            array_push($redactions, ...array_values($this->harness['environment']));
        }

        $logPath = $logRoot . '/' . $id . '.log';
        $logRelative = $logRootRelative . '/' . $id . '.log';
        $log = fopen($logPath, 'wb');
        if (!is_resource($log)) {
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'cannot create retained log');
        }
        chmod($logPath, 0600);

        $executable = $this->resolveExecutable($suite['command'][0], $workspace);
        if ($executable === null) {
            fclose($log);
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'suite executable cannot be resolved');
        }
        $executableDigest = hash_file('sha256', $executable);
        if (!is_string($executableDigest)
            || ($this->suiteExecutableBindings[$id] ?? null) !== 'sha256:' . $executableDigest) {
            fclose($log);
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'resolved suite executable disagrees with its bound digest');
        }
        $processGroupFile = $controlRoot . '/process-group.pid';
        $processStatusFile = $controlRoot . '/process-status.json';
        $started = hrtime(true);
        $process = proc_open(
            array_merge(
                [
                    $this->setsidPath, '--fork', '--wait', PHP_BINARY, __DIR__ . '/process-entry.php',
                    $processGroupFile, $processStatusFile, $executable,
                ],
                array_slice($suite['command'], 1),
            ),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workspace,
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            fclose($log);
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'cannot start suite process');
        }
        $stdin = $pipes[0] ?? null;
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            proc_terminate($process, 9);
            proc_close($process);
            fclose($log);
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'suite process pipes are unavailable');
        }
        fclose($stdin);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        $identityDeadline = hrtime(true) + 2_000_000_000;
        do {
            $identity = @file_get_contents($processGroupFile);
            if (is_string($identity) && preg_match('/^[1-9][0-9]*\n$/D', $identity) === 1) {
                break;
            }
            $identityStatus = proc_get_status($process);
            if (!$identityStatus['running']) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $identityDeadline);
        if (!is_string($identity) || preg_match('/^[1-9][0-9]*\n$/D', $identity) !== 1) {
            proc_terminate($process, 9);
            fclose($stdout);
            fclose($stderr);
            proc_close($process);
            fclose($log);
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'suite process group identity was not established');
        }
        $processGroup = (int) trim($identity);
        if (!function_exists('posix_getpgid') || @posix_getpgid($processGroup) !== $processGroup) {
            proc_terminate($process, 9);
            fclose($stdout);
            fclose($stderr);
            proc_close($process);
            fclose($log);
            $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
            return $this->infrastructureResult($id, 'suite process group identity is invalid');
        }
        $buffers = [1 => '', 2 => ''];
        $timedOut = false;
        $logOk = true;
        $lastStatus = proc_get_status($process);
        $this->activeProcessGroup = $processGroup;
        while (true) {
            $read = [];
            if (!feof($stdout)) {
                $read[] = $stdout;
            }
            if (!feof($stderr)) {
                $read[] = $stderr;
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $streamId = $stream === $stdout ? 1 : 2;
                    $chunk = fread($stream, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $buffers[$streamId] .= $chunk;
                        $logOk = $this->flushCompleteOutput($id, $buffers[$streamId], $streamId === 2 ? STDERR : STDOUT, $log, $redactions) && $logOk;
                    }
                }
            }
            $lastStatus = proc_get_status($process);
            if (!$lastStatus['running']) {
                break;
            }
            if ($this->interrupted) {
                break;
            }
            if ((hrtime(true) - $started) / 1_000_000_000 >= $suite['timeout_seconds']) {
                $timedOut = true;
                break;
            }
        }

        $groupCleanup = true;
        $terminationRequested = $timedOut || $this->interrupted || $lastStatus['running'];
        if ($terminationRequested) {
            $this->terminateGroup($processGroup);
        }
        foreach ([1 => $stdout, 2 => $stderr] as $streamId => $stream) {
            $tail = stream_get_contents($stream);
            if (is_string($tail) && $tail !== '') {
                $buffers[$streamId] .= $tail;
            }
            $logOk = $this->flushRemainingOutput($id, $buffers[$streamId], $streamId === 2 ? STDERR : STDOUT, $log, $redactions) && $logOk;
            fclose($stream);
        }
        $statusAfter = proc_get_status($process);
        $exitCode = proc_close($process);
        if ($exitCode === -1 && $statusAfter['exitcode'] >= 0) {
            $exitCode = $statusAfter['exitcode'];
        } elseif ($exitCode === -1 && $lastStatus['exitcode'] >= 0) {
            $exitCode = $lastStatus['exitcode'];
        }
        $reportedStatus = null;
        $statusBytes = @file_get_contents($processStatusFile);
        if (is_string($statusBytes)) {
            try {
                $decodedStatus = json_decode($statusBytes, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decodedStatus)
                    && is_int($decodedStatus['exit_code'] ?? null)
                    && is_bool($decodedStatus['signaled'] ?? null)
                    && (is_int($decodedStatus['signal'] ?? null) || ($decodedStatus['signal'] ?? null) === null)) {
                    $reportedStatus = $decodedStatus;
                }
            } catch (\JsonException) {
                $reportedStatus = null;
            }
        }
        if ($terminationRequested && $this->groupExists($processGroup)) {
            $groupCleanup = $this->terminateGroup($processGroup);
        }
        $lingeringDescendants = !$timedOut && !$this->interrupted && $this->groupExists($processGroup);
        if ($lingeringDescendants) {
            $groupCleanup = $this->terminateGroup($processGroup) && $groupCleanup;
        }
        $this->activeProcessGroup = null;
        $signal = null;
        if (is_array($reportedStatus) && $reportedStatus['signaled'] && is_int($reportedStatus['signal'])) {
            $signal = $reportedStatus['signal'];
            $exitCode = $reportedStatus['exit_code'];
        } elseif (is_array($reportedStatus)) {
            $exitCode = $reportedStatus['exit_code'];
        } elseif ($statusAfter['signaled'] || $lastStatus['signaled']) {
            $signal = (int) ($statusAfter['termsig'] ?: $lastStatus['termsig']);
        } elseif ($timedOut) {
            $signal = 15;
        } elseif ($this->interrupted) {
            $signal = $this->interruptSignal;
        }
        $logOk = fflush($log) && $logOk;
        fclose($log);

        $artifacts = [];
        $artifactFailure = null;
        foreach ($suite['expected_outputs'] as $expectedOutput) {
            $artifact = $this->artifactResult($workspace, $expectedOutput);
            if ($artifact === null) {
                $artifactFailure = "declared output was not produced: $expectedOutput";
                break;
            }
            if ($worktreeCreated) {
                $source = $workspace . '/' . $expectedOutput;
                $destination = $this->absoluteArtifactPath($expectedOutput);
                if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) {
                    $artifactFailure = "cannot retain declared output: $expectedOutput";
                    break;
                }
                if (!copy($source, $destination) || !chmod($destination, 0600)) {
                    $artifactFailure = "cannot retain declared output: $expectedOutput";
                    break;
                }
            }
            $artifacts[] = $artifact;
        }

        $workspaceAfter = $enforceWorkspaceIntegrity
            ? $this->workspaceFingerprint($workspace, $fingerprintExcludedTrees, $fingerprintExcludedExactPaths)
            : [];
        $workspaceChanged = $enforceWorkspaceIntegrity && $workspaceBefore !== $workspaceAfter;
        $workspaceCleanup = $this->cleanupWorkspace($worktreeCreated, $workspace, $controlRoot, $lock);
        if (!$workspaceCleanup) {
            $cleanupMessage = 'workspace cleanup failed';
        }
        $logDigest = hash_file('sha256', $logPath);
        if (!is_string($logDigest)) {
            $logOk = false;
        }
        $duration = (int) round((hrtime(true) - $started) / 1_000_000);
        $state = 'pass';
        $message = null;
        if ($this->interrupted) {
            $state = 'infra_error';
            $message = 'runner interrupted';
        } elseif (!$timedOut && $reportedStatus === null) {
            $state = 'infra_error';
            $message = 'suite process status was not published';
        } elseif (!$groupCleanup || !$workspaceCleanup || !$logOk) {
            $state = 'infra_error';
            $message = !$groupCleanup ? 'process-group cleanup failed' : ($cleanupMessage ?? 'retained log write or digest failed');
        } elseif ($workspaceChanged) {
            $state = 'infra_error';
            $message = 'suite changed files outside permitted output roots';
        } elseif ($timedOut) {
            $state = 'fail';
            $message = 'suite timed out';
        } elseif ($lingeringDescendants) {
            $state = 'fail';
            $message = 'suite left background descendants';
        } elseif ($artifactFailure !== null) {
            $state = 'fail';
            $message = $artifactFailure;
        } elseif ($exitCode === 69) {
            $state = 'infra_error';
            $message = 'required owner export is unavailable';
        } elseif ($exitCode !== 0) {
            $state = 'fail';
            $message = 'suite exited nonzero';
        }
        return [
            'id' => $id,
            'state' => $state,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'signal' => $signal,
            'cleanup' => $groupCleanup && $workspaceCleanup ? ($lingeringDescendants ? 'descendants_terminated' : 'pass') : 'fail',
            'duration_ms' => $duration,
            'log_path' => $logRelative,
            'log_sha256' => is_string($logDigest) ? 'sha256:' . $logDigest : null,
            'artifacts' => $artifacts,
            'message' => $message,
        ];
    }

    /** @return SuiteResult */
    private function infrastructureResult(string $id, string $message): array
    {
        return $this->result($id, 'infra_error', $message);
    }

    /** @return SuiteResult */
    private function failureResult(string $id, string $message): array
    {
        return $this->result($id, 'fail', $message);
    }

    /** @return SuiteResult */
    private function result(string $id, string $state, string $message): array
    {
        return [
            'id' => $id,
            'state' => $state,
            'exit_code' => null,
            'timed_out' => false,
            'signal' => null,
            'cleanup' => 'not_applicable',
            'duration_ms' => 0,
            'log_path' => null,
            'log_sha256' => null,
            'artifacts' => [],
            'message' => $message,
        ];
    }

    /** @param list<SuiteResult> $results */
    private function aggregateState(array $results): string
    {
        $states = array_column($results, 'state');
        if (in_array('infra_error', $states, true)) {
            return 'infra_error';
        }
        if (in_array('fail', $states, true)) {
            return 'fail';
        }
        if ($states !== [] && count(array_unique($states)) === 1 && $states[0] === 'not_applicable') {
            return 'not_applicable';
        }
        return $states !== [] && !in_array('not_applicable', $states, true) ? 'pass' : 'infra_error';
    }

    private function authority(?string $profileId): string
    {
        if ($this->catalogScope['kind'] !== 'complete') {
            return 'non_authorizing_partial';
        }
        if ($profileId !== null) {
            return 'gate_result';
        }
        if ($this->selection !== null && $this->selection['authority'] === 'advisory') {
            return 'non_authorizing_advisory';
        }
        if ($this->shard !== null) {
            return 'non_authorizing_shard';
        }
        return 'non_authorizing_diagnostic';
    }

    /** @return array<string,mixed>|null */
    private function harnessSummary(): ?array
    {
        if ($this->harness === null) {
            return null;
        }
        return [
            'approval_id' => $this->harness['approval_id'],
            'approval_sha256' => $this->harness['approval_sha256'],
            'provisioning_sha256' => $this->harness['provisioning_sha256'],
            'probe_sha256' => $this->harness['probe_sha256'],
            'image_digest' => $this->harness['image_digest'],
            'environment_class' => $this->harness['environment_class'],
            'data_profile' => $this->harness['data_profile'],
            'credential_realm' => $this->harness['credential_realm'],
            'egress_policy' => $this->harness['egress_policy'],
            'effect_policy' => $this->harness['effect_policy'],
            'sandbox_destinations_sha256' => $this->harness['sandbox_destinations_sha256'],
            'environment_fingerprint' => $this->harness['environment_fingerprint'],
            'output_authority' => $this->harness['output_authority'],
            'output_adoptability' => $this->harness['output_adoptability'],
        ];
    }

    /** @param Suite $suite */
    private function harnessMismatch(array $suite): ?string
    {
        if ($this->harness === null) {
            return 'non-offline suite requires harness-approval, keyring, provisioning, and fresh probe preflight';
        }
        if ($suite['required_review_gate'] !== 'duo-harness-approval/v1') {
            return 'non-offline suite does not name the required harness approval contract';
        }
        $authority = $suite['authority_requirements'] ?? null;
        if (!is_array($authority)
            || $authority['output_authority'] !== $this->harness['output_authority']
            || $authority['output_adoptability'] !== $this->harness['output_adoptability']) {
            return 'suite authority class disagrees with the verified non-authorizing harness';
        }
        $expectedDataProfile = $authority['data_profile'] === 'synthetic'
            ? 'approved_synthetic'
            : $authority['data_profile'];
        if ($expectedDataProfile !== $this->harness['data_profile']) {
            return 'suite data profile disagrees with the verified harness';
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function selectionSummary(): ?array
    {
        if ($this->selection === null) {
            return null;
        }
        return [
            'selector_version' => $this->selection['selector_version'],
            'authority' => $this->selection['authority'],
            'state' => $this->selection['state'],
            'reason' => $this->selection['reason'],
            'base_sha' => $this->selection['base_sha'],
            'head_sha' => $this->selection['head_sha'],
            'merge_base_sha' => $this->selection['merge_base_sha'],
            'changed_paths_sha256' => $this->selection['changed_paths_sha256'],
        ];
    }

    /**
     * @param Suite $suite
     * @return array<string,mixed>
     */
    private function executionPlan(array $suite): array
    {
        return [
            'id' => $suite['id'],
            'argv' => array_map(fn(string $argument): string => $this->redactArgument($argument), $suite['command']),
            'timeout_seconds' => $suite['timeout_seconds'],
            'workspace_mode' => $suite['workspace_mode'],
            'temporary_directory' => $suite['temporary_directory'],
            'environment_class' => $suite['environment_class'],
            'required_tools' => $suite['required_tools'],
            'required_services' => $suite['required_services'],
            'resource_locks' => $suite['resource_locks'],
            'expected_outputs' => $suite['expected_outputs'],
        ];
    }

    /** @param list<Suite> $suites */
    private function bindToolchain(array $suites): void
    {
        $setsid = $this->findTool('setsid');
        if ($setsid === null || !function_exists('posix_kill')) {
            throw new CatalogException('setsid and posix_kill are required for process-group cleanup');
        }
        $this->setsidPath = $setsid;
        $this->bindToolchainPath('php', PHP_BINARY);
        $this->bindToolchainPath('runner.entry', __DIR__ . '/runner.php');
        $this->bindToolchainPath('runner.harness-approval', __DIR__ . '/HarnessApproval.php');
        $this->bindToolchainPath('runner.process-entry', __DIR__ . '/process-entry.php');
        $this->bindToolchainPath('runner.reports', __DIR__ . '/Reports.php');
        $this->bindToolchainPath('runner.selection', __DIR__ . '/Selection.php');
        $this->bindToolchainPath('runner.shard-plan', __DIR__ . '/ShardPlan.php');
        $this->bindToolchainPath('runner.setsid', $setsid);
        foreach ($suites as $suite) {
            $executable = $this->resolveExecutable($suite['command'][0], $this->root);
            if ($executable === null) {
                throw new CatalogException('suite executable cannot be resolved: ' . $suite['id']);
            }
            $key = 'suite.' . $suite['id'] . '.executable';
            $this->bindToolchainPath($key, $executable);
            $this->suiteExecutableBindings[$suite['id']] = $this->toolchainBindings[$key];
            foreach ($suite['required_tools'] as $tool) {
                $path = $this->findTool($tool);
                if ($path === null) {
                    throw new CatalogException("required tool is unavailable: $tool");
                }
                $this->bindToolchainPath('required.' . $tool, $path);
            }
        }
        ksort($this->toolchainBindings, SORT_STRING);
        ksort($this->toolchainPaths, SORT_STRING);
        ksort($this->suiteExecutableBindings, SORT_STRING);
    }

    private function bindToolchainPath(string $key, string $path): void
    {
        $this->toolchainPaths[$key] = $path;
        $this->toolchainBindings[$key] = $this->fileDigest($path);
    }

    private function changedToolchainBinding(): ?string
    {
        foreach ($this->toolchainPaths as $key => $path) {
            $digest = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($digest) || 'sha256:' . $digest !== $this->toolchainBindings[$key]) {
                return $key;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function inheritedRunnerOutputTrees(): array
    {
        $raw = getenv('DUO_RUNNER_OWNED_OUTPUT_TREES');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $paths = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($paths) || !array_is_list($paths)) {
            return [];
        }
        $validated = [];
        foreach ($paths as $path) {
            if (!is_string($path)
                || preg_match(
                    '~^artifacts/test-results/runs/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}-[a-f0-9]{8}$~D',
                    $path,
                ) !== 1
                || !is_dir($this->root . '/' . $path)
                || is_link($this->root . '/' . $path)) {
                return [];
            }
            $validated[] = $path;
        }
        return $validated;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function validateParallelOutputs(array $paths, string $pattern, string $label): array
    {
        if (count($paths) > 64 || count(array_unique($paths, SORT_STRING)) !== count($paths)) {
            throw new CatalogException("runner-owned parallel output $label list is invalid");
        }
        foreach ($paths as $path) {
            if (preg_match($pattern, $path) !== 1) {
                throw new CatalogException("runner-owned parallel output $label is invalid");
            }
        }
        return $paths;
    }

    /** @return array{profile_id:string,contract_sha256:string} */
    private function platformContract(): array
    {
        $path = __DIR__ . '/platform-profiles.json';
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException('reviewed platform profiles are unavailable');
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('reviewed platform profiles are malformed: ' . $exception->getMessage());
        }
        if (!is_array($document)
            || ($document['format'] ?? null) !== 'duo-development-platform-profiles/v1'
            || !is_array($document['profiles'] ?? null)
            || !array_is_list($document['profiles'])) {
            throw new CatalogException('reviewed platform profiles are malformed');
        }
        $architecture = match (strtolower(php_uname('m'))) {
            'amd64', 'x86_64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => null,
        };
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ($document['profiles'] as $profile) {
            if (!is_array($profile)
                || array_keys($profile) !== ['id', 'php_major_minor', 'os_family', 'architecture']
                || !is_string($profile['id'])
                || !is_string($profile['php_major_minor'])
                || !is_string($profile['os_family'])
                || !is_string($profile['architecture'])) {
                throw new CatalogException('reviewed platform profiles are malformed');
            }
            if ($profile['php_major_minor'] !== $version
                || $profile['os_family'] !== PHP_OS_FAMILY
                || $profile['architecture'] !== $architecture) {
                continue;
            }
            return [
                'profile_id' => $profile['id'],
                'contract_sha256' => 'sha256:' . hash('sha256', $this->canonical($profile)),
            ];
        }
        throw new CatalogException('current development platform has no reviewed public profile');
    }

    /**
     * @param list<string> $redactions
     * @param resource $stream
     * @param resource $log
     */
    private function flushCompleteOutput(string $id, string &$buffer, $stream, $log, array $redactions): bool
    {
        $ok = true;
        while (($position = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $position + 1);
            $buffer = substr($buffer, $position + 1);
            $safe = $this->redactOutput($line, $redactions);
            $ok = fwrite($log, $safe) === strlen($safe) && $ok;
            fwrite($stream, "[$id] $safe");
        }
        if (strlen($buffer) > self::OUTPUT_BUFFER_LIMIT) {
            $length = strlen($buffer) - self::OUTPUT_BUFFER_RETAIN;
            $chunk = substr($buffer, 0, $length);
            $buffer = substr($buffer, $length);
            $safe = $this->redactOutput($chunk, $redactions);
            $ok = fwrite($log, $safe) === strlen($safe) && $ok;
            fwrite($stream, "[$id] $safe");
        }
        return $ok;
    }

    /**
     * @param list<string> $redactions
     * @param resource $stream
     * @param resource $log
     */
    private function flushRemainingOutput(string $id, string &$buffer, $stream, $log, array $redactions): bool
    {
        if ($buffer === '') {
            return true;
        }
        $safe = $this->redactOutput($buffer, $redactions);
        $buffer = '';
        $ok = fwrite($log, $safe) === strlen($safe);
        fwrite($stream, "[$id] $safe" . (str_ends_with($safe, "\n") ? '' : "\n"));
        return $ok;
    }

    /** @param list<string> $redactions */
    private function redactOutput(string $bytes, array $redactions): string
    {
        usort($redactions, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($redactions as $redaction) {
            $bytes = str_replace($redaction, '<redacted-path>', $bytes);
        }
        $bytes = (string) preg_replace(
            '/(?i)(password|token|secret|credential|authorization|cookie)(\s*[:=]\s*)[^\s]+/',
            '$1$2<redacted>',
            $bytes,
        );
        $bytes = (string) preg_replace('#(?<![A-Za-z0-9:])/(?:[A-Za-z0-9._@%+=,~\-]+/?)+#', '<redacted-path>', $bytes);
        $bytes = (string) preg_replace('#(?<![A-Za-z0-9])[A-Za-z]:\\\\(?:[^\s\\\\]+\\\\?)+#', '<redacted-path>', $bytes);
        return $bytes;
    }

    private function redactArgument(string $argument): string
    {
        $argument = str_replace($this->root, '<repo>', $argument);
        return (string) preg_replace(
            '/(?i)^(--?(?:password|token|secret|credential|authorization)(?:=|:)).*$/',
            '$1<redacted>',
            $argument,
        );
    }

    /**
     * @param list<string> $excludedTrees
     * @param list<string> $excludedExactPaths
     * @return array<string,string|int>
     */
    private function workspaceFingerprint(string $workspace, array $excludedTrees, array $excludedExactPaths): array
    {
        $trees = [];
        foreach ($excludedTrees as $path) {
            $trees[] = trim(str_replace('\\', '/', $path), '/');
        }
        $exact = [];
        foreach ($excludedExactPaths as $path) {
            $exact[trim(str_replace('\\', '/', $path), '/')] = true;
        }
        $isExcludedTree = static function (string $relative) use ($trees): bool {
            $relative = trim(str_replace('\\', '/', $relative), '/');
            foreach ($trees as $path) {
                if ($path !== '' && ($relative === $path || str_starts_with($relative, $path . '/'))) {
                    return true;
                }
            }
            return false;
        };
        $result = [];
        $directory = new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $info) use ($workspace, $isExcludedTree): bool {
                $relative = substr($info->getPathname(), strlen($workspace) + 1);
                return !$isExcludedTree($relative);
            },
        );
        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $candidate) {
            if (!$candidate instanceof \SplFileInfo) {
                throw new CatalogException('workspace traversal returned an invalid entry');
            }
            $info = $candidate;
            $path = $info->getPathname();
            $relative = substr($path, strlen($workspace) + 1);
            if (isset($exact[trim(str_replace('\\', '/', $relative), '/')])) {
                continue;
            }
            if ($info->isLink()) {
                $result[$relative] = 'link:' . (string) readlink($path);
            } elseif ($info->isFile()) {
                $digest = hash_file('sha256', $path);
                $result[$relative] = 'file:' . ($digest === false ? 'unreadable' : $digest) . ':' . ($info->getPerms() & 0777);
            } elseif ($info->isDir()) {
                $result[$relative] = 'dir:' . ($info->getPerms() & 0777);
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @param resource|null $lock */
    private function cleanupWorkspace(bool $worktreeCreated, string $workspace, string $controlRoot, mixed $lock): bool
    {
        $ok = true;
        if ($worktreeCreated) {
            try {
                $this->capture(['git', 'worktree', 'remove', '--force', $workspace], $this->root);
                $this->capture(['git', 'worktree', 'prune'], $this->root);
            } catch (CatalogException) {
                $ok = false;
            }
        }
        if (is_resource($lock)) {
            $ok = flock($lock, LOCK_UN) && $ok;
            fclose($lock);
        }
        return $this->removeTree($controlRoot) && $ok;
    }

    /** @phpstan-impure */
    private function groupExists(int $processGroup): bool
    {
        return $processGroup > 0 && @posix_kill(-$processGroup, 0);
    }

    private function terminateGroup(int $processGroup): bool
    {
        if ($processGroup <= 0 || !function_exists('posix_kill')) {
            return false;
        }
        if (!$this->groupExists($processGroup)) {
            return true;
        }
        @posix_kill(-$processGroup, 15);
        $deadline = hrtime(true) + 500_000_000;
        while ($this->groupExists($processGroup) && hrtime(true) < $deadline) {
            usleep(10000);
        }
        if ($this->groupExists($processGroup)) {
            @posix_kill(-$processGroup, 9);
            $deadline = hrtime(true) + 500_000_000;
            while ($this->groupExists($processGroup) && hrtime(true) < $deadline) {
                usleep(10000);
            }
        }
        return !$this->groupExists($processGroup);
    }

    /** @return array<int,callable|int> */
    private function installSignalHandlers(): array
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            throw new CatalogException('pcntl signal handling is required');
        }
        pcntl_async_signals(true);
        $previous = [];
        foreach ([SIGINT, SIGTERM] as $signal) {
            $previous[$signal] = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler($signal) : SIG_DFL;
            pcntl_signal($signal, function (int $received): void {
                if ($this->finalizing) {
                    return;
                }
                $this->interrupted = true;
                $this->interruptSignal = $received;
                if ($this->activeProcessGroup !== null) {
                    @posix_kill(-$this->activeProcessGroup, 15);
                }
            });
        }
        return $previous;
    }

    /** @param array<int,callable|int> $handlers */
    private function restoreSignalHandlers(array $handlers): void
    {
        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    private function findTool(string $tool): ?string
    {
        if ($tool === '' || str_contains($tool, '/')) {
            return null;
        }
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            $candidate = $directory . '/' . $tool;
            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                $resolved = realpath($candidate);
                return is_string($resolved) ? $resolved : $candidate;
            }
        }
        return null;
    }

    private function resolveExecutable(string $command, string $workspace): ?string
    {
        if (str_contains($command, '/')) {
            $candidate = $workspace . '/' . $command;
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }
        return $this->findTool($command);
    }

    /** @param list<string> $argv */
    private function capture(array $argv, string $workingDirectory): string
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot run ' . $argv[0]);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new CatalogException($argv[0] . ' failed: ' . trim((string) $stderr));
        }
        return $stdout;
    }

    private function publish(string $path, string $bytes): void
    {
        $absolute = $this->absoluteArtifactPath($path);
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new CatalogException('cannot create result directory');
        }
        $this->assertNoSymlinkAncestors($absolute);
        $temporary = tempnam($directory, '.result.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create result temporary file');
        }
        chmod($temporary, 0600);
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $absolute)) {
                throw new CatalogException('cannot publish result');
            }
            chmod($absolute, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function publishPreflightFailure(
        string $root,
        string $resultPath,
        ?string $profileId,
        ?string $partialOwner,
        string $message,
    ): void {
        $relative = self::normalizeArtifactPath($root, $resultPath);
        $absolute = $root . '/' . $relative;
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $candidate = null;
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) === 0 && is_string($stdout) && preg_match('/^[a-f0-9]{40}$/D', trim($stdout)) === 1) {
                $candidate = trim($stdout);
            }
        }
        $catalogScope = $partialOwner === null
            ? ['kind' => 'complete', 'owner' => null]
            : ['kind' => 'partial_owner', 'owner' => $partialOwner];
        $receipt = [
            'format' => 'duo-test-run-receipt/v1',
            'phase' => 'preflight',
            'authority' => $catalogScope['kind'] !== 'complete'
                ? 'non_authorizing_partial'
                : ($profileId === null ? 'non_authorizing_diagnostic' : 'gate_result'),
            'state' => 'infra_error',
            'message' => $message,
            'candidate_sha' => $candidate,
            'candidate_dirty' => null,
            'catalog_scope' => $catalogScope,
            'catalog_sha256' => null,
            'catalog_schema_sha256' => null,
            'receipt_schema_sha256' => null,
            'invocation_schema_sha256' => null,
            'selection_schema_sha256' => null,
            'profile_id' => $profileId,
            'profile_sha256' => null,
            'selection' => null,
            'selection_sha256' => null,
            'shard' => null,
            'shard_plan_schema_sha256' => null,
            'selected_suite_ids' => [],
            'selected_set_sha256' => null,
            'execution_plan' => [],
            'runner_sha256' => null,
            'dependency_lock_sha256' => null,
            'toolchain' => [],
            'image_digest' => null,
            'harness' => null,
            'platform_contract' => null,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => 0,
            'interrupt_signal' => null,
            'profile_artifacts' => [],
            'report_artifacts' => [],
            'results' => [[
                'id' => 'runner.preflight',
                'state' => 'infra_error',
                'exit_code' => null,
                'timed_out' => false,
                'signal' => null,
                'cleanup' => 'not_applicable',
                'duration_ms' => 0,
                'log_path' => null,
                'log_sha256' => null,
                'artifacts' => [],
                'message' => $message,
            ]],
        ];
        $canonical = self::canonicalStatic($receipt) . "\n";
        $temporary = tempnam($directory, '.result.');
        if (!is_string($temporary)) {
            return;
        }
        chmod($temporary, 0600);
        if (file_put_contents($temporary, $canonical) === strlen($canonical)) {
            rename($temporary, $absolute);
            chmod($absolute, 0600);
        }
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }

    private function removeTree(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return !file_exists($path) || unlink($path);
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            return false;
        }
        $ok = true;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $ok = $this->removeTree($path . '/' . $entry) && $ok;
            }
        }
        return rmdir($path) && $ok;
    }

    /** @return ArtifactResult|null */
    private function artifactResult(string $workspace, string $path): ?array
    {
        self::normalizeArtifactPath($workspace, $path);
        $absolute = $workspace . '/' . $path;
        if (!is_file($absolute) || is_link($absolute)) {
            return null;
        }
        $digest = hash_file('sha256', $absolute);
        return is_string($digest) ? ['path' => $path, 'sha256' => 'sha256:' . $digest] : null;
    }

    private function fileDigest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new CatalogException('cannot hash required input ' . basename($path));
        }
        return 'sha256:' . $digest;
    }

    public static function normalizeArtifactPath(string $root, string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || !str_starts_with($path, 'artifacts/') || str_ends_with($path, '/')
            || in_array('..', explode('/', $path), true)) {
            throw new CatalogException('unsafe result or artifact path');
        }
        $absolute = rtrim($root, '/') . '/' . $path;
        $cursor = rtrim($root, '/');
        foreach (explode('/', $path) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new CatalogException('artifact path crosses a symbolic link');
            }
        }
        if (!str_starts_with($absolute, rtrim($root, '/') . '/artifacts/')) {
            throw new CatalogException('artifact path escapes the repository output root');
        }
        return $path;
    }

    private function absoluteArtifactPath(string $path): string
    {
        return $this->root . '/' . self::normalizeArtifactPath($this->root, $path);
    }

    private function assertNoSymlinkAncestors(string $absolute): void
    {
        $relative = substr($absolute, strlen(rtrim($this->root, '/')) + 1);
        self::normalizeArtifactPath($this->root, $relative);
    }

    private function canonical(mixed $value): string
    {
        return self::canonicalStatic($value);
    }

    private static function canonicalStatic(mixed $value): string
    {
        return json_encode(self::canonicalValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::canonicalValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalValue($item);
        }
        return $value;
    }
}
