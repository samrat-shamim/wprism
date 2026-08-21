<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/EnvironmentCommand.php';
require_once __DIR__ . '/OriginCommand.php';
require_once __DIR__ . '/../Environment/PortablePreviewMaterializer.php';
require_once __DIR__ . '/../Plan/PlanContract.php';
require_once __DIR__ . '/../Plan/PlanSummary.php';
require_once __DIR__ . '/../Environment/PreviewRunJournal.php';
require_once __DIR__ . '/../Refresh/CloudOriginExportClient.php';
require_once __DIR__ . '/../Refresh/Refresh.php';
require_once __DIR__ . '/../Environment/CloudPreviewTransport.php';
require_once __DIR__ . '/../Environment/EnvironmentTransportFactory.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';

/** Public composition for one outbound origin export and one Duo Cloud slot. */
final class PreviewCommand {
    private const DEFAULT_TTL = 86400;
    private const MAX_ORIGIN_DRIVES_PER_INVOCATION = 8;
    private const MAX_ORIGIN_DRIVE_SEQUENCE = 1023;
    // PreviewSlotLifecycle admits at most 128 receipts per generation. A full
    // sleep/wake cycle consumes four while materialization and terminal reap
    // need their own fixed headroom, so operator-driven cycles stop at 24.
    private const MAX_SLEEP_CYCLES = 24;
    private const PUBLICATION_FORMAT = 'duo-cloud-preview-candidate-publication/v1';
    private const CLEANUP_FORMAT = 'duo-cloud-preview-candidate-cleanup/v1';
    private const REAP_CLEANUP_FORMAT = 'duo-cloud-preview-candidate-reap-cleanup/v1';
    private const OBSERVATION_FORMAT = 'duo-portable-preview-observation/v1';
    private const LOCAL_REAP_FORMAT = 'duo-portable-preview-local-reap/v1';
    private const SLEEP_RECEIPT_FORMAT = 'duo-cloud-preview-sleep-transition/v1';

    /**
     * @param list<string> $args
     * @param callable(EnvironmentDriver,array<string,mixed>):(array<string,mixed>|int) $promote
     * @param callable(EnvironmentDriver,?array<string,mixed>):array<string,mixed> $observe
     */
    public static function run(
        array $args,
        ?string $envsFileOverride,
        string $startDirectory,
        callable $promote,
        callable $observe
    ): int {
        $json = in_array('--format=json', $args, true);
        try {
            $options = self::parse($args);
            $renderingPromote = $promote;
            $promote = static function (
                EnvironmentDriver $driver,
                array $context
            ) use ($renderingPromote): array|int {
                return self::withoutPublicPromotionOutput(
                    static fn(): array|int => $renderingPromote($driver, $context)
                );
            };
            $root = self::repositoryRoot($startDirectory);
            $registry = Registry::load($envsFileOverride, $root);
            $targetConfig = Registry::get($registry, $options['target']);
            $target = EnvironmentTransportFactory::make($options['target'], $targetConfig);
            if (!$target instanceof CloudPreviewTransport) {
                throw new \RuntimeException(
                    "preview target '{$options['target']}' is not a machine-local cloud-preview transport"
                );
            }
            $journal = new PreviewRunJournal(self::gitCommonDir($root) . '/duo-previews');
            $receipt = $journal->synchronizedTarget(
                $options['target'],
                static function () use (
                    $envsFileOverride,
                    $journal,
                    $observe,
                    $options,
                    $promote,
                    $root,
                    $startDirectory,
                    $target
                ): array {
                    if ($options['action'] === 'reap') {
                        return self::reap(
                            $root,
                            $target,
                            $journal,
                            EnvironmentCommand::journal(),
                            $options['target']
                        );
                    }
                    if (in_array($options['action'], ['sleep', 'wake'], true)) {
                        return self::sleepTransition(
                            $target,
                            $journal,
                            $options['target'],
                            $options['action']
                        );
                    }
                    return self::create(
                        $options,
                        $root,
                        $target,
                        $journal,
                        $envsFileOverride,
                        $startDirectory,
                        $promote,
                        $observe
                    );
                }
            );
            self::render($receipt, $json, $options['action']);
            return 0;
        } catch (\Throwable $failure) {
            if ($json) {
                return CommandOutput::renderRefusalJson(
                    'preview',
                    'preview_refused',
                    'the universal cloud preview could not be completed safely',
                    'inspect the local private preview journal, correct the blocker, then retry the same intent',
                    [[
                        'code' => 'preview_blocker',
                        'message' => 'a private preview blocker prevented completion',
                        'remediation' => 'correct this blocker without changing the intent, or reap before starting another preview',
                    ]]
                );
            }
            fwrite(STDERR, 'duo: preview: ' . self::publicFailure($failure->getMessage()) . "\n");
            return 1;
        }
    }

    /**
     * The frozen promotion predates the preview JSON envelope and writes
     * target diagnostics directly to both process streams. Suppress that
     * legacy renderer while either public composition owns the call; the
     * signed transport result remains available to private service logs and
     * the caller receives only the host-rendered success or value-free
     * refusal after this boundary closes.
     *
     * @param callable():(array<string,mixed>|int) $callback
     * @return array<string,mixed>|int
     */
    private static function withoutPublicPromotionOutput(callable $callback): array|int {
        static $registered = false;
        if (!$registered) {
            $filterClass = get_class(new class() extends \php_user_filter {
                /** @param resource $in @param resource $out */
                public function filter($in, $out, &$consumed, bool $closing): int {
                    while ($bucket = stream_bucket_make_writeable($in)) {
                        $consumed += $bucket->datalen;
                    }
                    return PSFS_PASS_ON;
                }
            });
            if (!stream_filter_register('duo.preview.public-promotion-output', $filterClass)) {
                throw new \RuntimeException('could not establish private preview diagnostic boundary');
            }
            $registered = true;
        }
        $stdoutFilter = stream_filter_append(
            STDOUT,
            'duo.preview.public-promotion-output',
            STREAM_FILTER_WRITE
        );
        if (!is_resource($stdoutFilter)) {
            throw new \RuntimeException('could not establish private preview diagnostic boundary');
        }
        $stderrFilter = stream_filter_append(
            STDERR,
            'duo.preview.public-promotion-output',
            STREAM_FILTER_WRITE
        );
        if (!is_resource($stderrFilter)) {
            stream_filter_remove($stdoutFilter);
            throw new \RuntimeException('could not establish private preview diagnostic boundary');
        }
        $level = ob_get_level();
        ob_start();
        try {
            return $callback();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            stream_filter_remove($stderrFilter);
            stream_filter_remove($stdoutFilter);
        }
    }

    /**
     * Produce the value-free convergence evidence injected into the portable
     * lifecycle before it releases the service fence. Recovery happens after
     * release, so it validates and returns the immutable prior receipt instead
     * of attempting an unauthorized target command.
     *
     * @param ?array<string,mixed> $prior
     * @return array<string,mixed>
     */
    public static function observeConvergence(
        EnvironmentDriver $driver,
        ?array $prior
    ): array {
        if ($prior !== null) {
            self::assertObservation($prior, $driver);
            return $prior;
        }
        $result = $driver->captureWp(CodeDeploy::controlArgs([
            'duo', 'plan', '--repo=' . $driver->repoPath(), '--format=json',
        ]));
        if (($result['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('portable preview convergence plan could not be read');
        }
        try {
            $decoded = json_decode(
                trim((string) ($result['stdout'] ?? '')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            throw new \RuntimeException('portable preview convergence returned malformed JSON');
        }
        $plan = PlanContract::requireComplete($decoded, 'portable preview convergence');
        if (!PlanSummary::render($plan)['ok']) {
            throw new \RuntimeException('portable preview did not converge to a clean code/state plan');
        }
        $observation = [
            'format' => self::OBSERVATION_FORMAT,
            'plan_sha256' => hash('sha256', EnvironmentLifecycleCanon::encode($plan)),
            'status' => 'converged',
            'target_driver_id' => $driver->driverId(),
            'target_environment' => $driver->name(),
        ];
        $observation['receipt_sha256'] = hash(
            'sha256',
            self::OBSERVATION_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($observation)
        );
        return $observation;
    }

    /**
     * @param array<string,mixed> $options
     * @param callable(EnvironmentDriver,array<string,mixed>):(array<string,mixed>|int) $promote
     * @param callable(EnvironmentDriver,?array<string,mixed>):array<string,mixed> $observe
     * @return array<string,mixed>
     */
    private static function create(
        array $options,
        string $root,
        CloudPreviewTransport $target,
        PreviewRunJournal $journal,
        ?string $envsFileOverride,
        string $startDirectory,
        callable $promote,
        callable $observe
    ): array {
        $source = self::sourceIdentity($root, $options['production_ref'], $options['new_branch']);
        $intent = [
            'candidate_branch' => $options['new_branch'],
            'origin_environment' => $options['origin'],
            'production_commit' => $source['production_commit'],
            'production_ref' => $options['production_ref'],
            'source_branch' => $source['source_branch'],
            'source_commit' => $source['source_commit'],
            'target_driver_id' => $target->driverId(),
            'target_environment' => $options['target'],
            'ttl_seconds' => $options['ttl_seconds'],
        ];
        $run = $journal->resumeOrStart($options['target'], $intent);
        $operationId = $run['operation_id'];
        $events = $run['events'];
        self::assertNoReapInProgress($events, $options['target']);
        $sleepCycle = self::latestSleepCycle($events);
        if ($sleepCycle !== null && self::sleepCycleEvent(
            $events,
            'sleep-fence-released',
            $sleepCycle['operation_id']
        ) === null) {
            throw new \RuntimeException(
                'preview generation is asleep or transitioning; use `duo preview wake` exactly'
            );
        }
        $complete = PreviewRunJournal::eventData($events, 'complete');
        if ($complete !== null) {
            return self::completionReceipt($complete);
        }
        self::assertSourceIdentity($root, $intent);

        $originClient = $target->originExportClient();
        $demandEvent = PreviewRunJournal::eventData($events, 'demand-created');
        if ($demandEvent === null) {
            self::recordOnce($journal, $operationId, 'demand-intent', [
                'expected_production_commit' => $intent['production_commit'],
            ]);
            $demand = $originClient->requestPortableExport(
                $intent['production_commit'],
                $operationId
            );
            $journal->append($operationId, 'demand-created', ['demand' => $demand]);
        } else {
            $demand = $demandEvent['demand'] ?? null;
            if (!is_array($demand) || array_is_list($demand)) {
                throw new \RuntimeException('journaled cloud-origin demand is malformed');
            }
        }

        $status = self::committedStatus(
            $originClient,
            $demand,
            $operationId,
            $journal,
            $options['origin'],
            $envsFileOverride,
            $startDirectory
        );
        $export = $originClient->readCommittedExport($demand, $status, $operationId);
        $manifest = $export->manifest();
        $verified = PreviewRunJournal::eventData($journal->events($operationId), 'export-verified');
        $exportEvidence = [
            'export_sha256' => hash('sha256', $export->canonicalBytes()),
            'manifest' => $manifest,
        ];
        if ($verified === null) {
            $journal->append($operationId, 'export-verified', $exportEvidence);
        } elseif (EnvironmentLifecycleCanon::encode($verified)
            !== EnvironmentLifecycleCanon::encode($exportEvidence)) {
            throw new \RuntimeException('committed cloud-origin export changed after verification');
        }

        $candidate = self::candidate(
            $root,
            $intent,
            $export,
            $operationId,
            $journal
        );
        self::assertSourceIdentity($root, $intent);

        $containment = $target->reviewedBaseContainment($operationId);
        $containmentEvent = PreviewRunJournal::eventData(
            $journal->events($operationId),
            'reviewed-base-verified'
        );
        if ($containmentEvent === null) {
            $journal->append($operationId, 'reviewed-base-verified', [
                'descriptor' => $containment,
            ]);
        } elseif (EnvironmentLifecycleCanon::encode($containmentEvent['descriptor'] ?? null)
            !== EnvironmentLifecycleCanon::encode($containment)) {
            throw new \RuntimeException('reviewed-base containment changed during preview recovery');
        }

        $repositoryAuthority = $target->repositoryAuthority($operationId);
        self::assertRepositoryAuthority($repositoryAuthority);
        $authorityEvent = PreviewRunJournal::eventData(
            $journal->events($operationId),
            'repository-authority-verified'
        );
        if ($authorityEvent === null) {
            $journal->append($operationId, 'repository-authority-verified', [
                'descriptor' => $repositoryAuthority,
            ]);
        } elseif (EnvironmentLifecycleCanon::encode($authorityEvent['descriptor'] ?? null)
            !== EnvironmentLifecycleCanon::encode($repositoryAuthority)) {
            throw new \RuntimeException('cloud repository authority changed during preview recovery');
        }
        self::assertSourceIdentity($root, $intent);

        $materializerIntent = [
            'branch_commit' => $candidate['head'],
            'branch_ref' => $options['new_branch'],
            'fidelity_omissions_sha256' => hash(
                'sha256',
                EnvironmentLifecycleCanon::encode(
                    PortablePreviewMaterializer::REQUIRED_FIDELITY_OMISSIONS
                )
            ),
            'mode' => 'create',
            'portable_export_manifest_sha256' => $manifest['manifest_sha256'],
            'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
            'reviewed_base_containment_sha256' => $containment['descriptor_sha256'],
            'reviewed_base_receipt_sha256' => $containment['reviewed_base']['review_receipt_sha256'],
            'source_environment' => 'portable-origin-export',
            'target_environment' => $target->name(),
            'ttl_seconds' => $options['ttl_seconds'],
        ];
        self::recordOnce($journal, $operationId, 'materializer-intent', [
            'intent_sha256' => hash(
                'sha256',
                EnvironmentLifecycleCanon::encode($materializerIntent)
            ),
        ]);

        $publishCandidate = static fn(array $expected, ?array $prior): array =>
            self::publishCandidate($root, $expected, $prior);
        $cleanupCandidate = static fn(array $publication, array $sync, ?array $prior): array =>
            self::cleanupCandidate($root, $publication, $sync, $prior);
        $portablePromote = static fn(EnvironmentDriver $driver, array $frozen): array =>
            self::promotePortable($driver, $frozen, $promote);
        $receipt = PortablePreviewMaterializer::materialize(
            $target,
            $target,
            EnvironmentCommand::journal(),
            [
                'branch' => $options['new_branch'],
                'branch_commit' => $candidate['head'],
                'fidelity_omissions' => PortablePreviewMaterializer::REQUIRED_FIDELITY_OMISSIONS,
                'portable_export' => $manifest,
                'repository_authority' => $repositoryAuthority,
                'reviewed_base' => $containment['reviewed_base'],
                'reviewed_base_containment' => $containment,
                'ttl_seconds' => $options['ttl_seconds'],
            ],
            $publishCandidate,
            $cleanupCandidate,
            $portablePromote,
            $observe
        );
        self::assertSourceIdentity($root, $intent);
        $journal->append($operationId, 'complete', ['receipt' => $receipt]);
        return $receipt;
    }

    /** @return array<string,mixed> */
    private static function sleepTransition(
        CloudPreviewTransport $target,
        PreviewRunJournal $journal,
        string $targetEnvironment,
        string $action
    ): array {
        $latest = self::activeCompletedPreview($journal, $targetEnvironment);
        $events = $latest['events'];
        self::assertNoReapInProgress($events, $targetEnvironment);
        $previewOperationId = $latest['operation_id'];
        $identity = self::previewIdentity($latest['receipt']);
        $cycle = self::latestSleepCycle($events);
        if ($cycle !== null) {
            $released = self::sleepCycleEvent($events, 'sleep-fence-released', $cycle['operation_id']);
            $slept = self::sleepCycleEvent($events, 'slept', $cycle['operation_id']);
            $wakeIntent = self::sleepCycleEvent($events, 'wake-intent', $cycle['operation_id']);
            $woken = self::sleepCycleEvent($events, 'woken', $cycle['operation_id']);
            if ($action === 'wake' && $released !== null) {
                if ($woken === null) {
                    throw new \RuntimeException('released preview sleep fence has no durable wake evidence');
                }
                return self::sleepReceipt(
                    $woken['result'],
                    'wake',
                    $targetEnvironment,
                    $previewOperationId,
                    $cycle['operation_id'],
                    true
                );
            }
            if ($action === 'sleep' && $released === null && $wakeIntent !== null) {
                throw new \RuntimeException('preview wake is incomplete; retry `duo preview wake` exactly');
            }
            if ($action === 'sleep' && $released === null && $slept !== null) {
                return self::sleepReceipt(
                    $slept['result'],
                    'sleep',
                    $targetEnvironment,
                    $previewOperationId,
                    $cycle['operation_id'],
                    true
                );
            }
            if ($action === 'wake' && $slept === null) {
                throw new \RuntimeException('preview sleep is incomplete; retry `duo preview sleep` exactly');
            }
        }
        if ($action === 'wake' && $cycle === null) {
            throw new \RuntimeException('preview is not asleep');
        }
        if ($action === 'wake'
            && self::sleepCycleEvent($events, 'sleep-fence-released', $cycle['operation_id']) !== null) {
            throw new \RuntimeException('preview is already awake');
        }
        if ($action === 'sleep' && ($cycle === null
            || self::sleepCycleEvent($events, 'sleep-fence-released', $cycle['operation_id']) !== null)) {
            $cycleNumber = self::sleepCycleCount($events) + 1;
            $cycle = [
                'cycle' => $cycleNumber,
                'identity' => $identity,
                'operation_id' => self::sleepOperationId(
                    $previewOperationId,
                    $cycleNumber,
                    $identity['ownership_receipt_sha256']
                ),
            ];
            $journal->append($previewOperationId, 'sleep-cycle-intent', $cycle);
            $events = $journal->events($previewOperationId);
        }
        if ($cycle === null || EnvironmentLifecycleCanon::encode($cycle['identity'])
            !== EnvironmentLifecycleCanon::encode($identity)) {
            throw new \RuntimeException('preview sleep cycle is stale or foreign to the active generation');
        }

        $operationId = $cycle['operation_id'];
        $capabilities = $target->capabilities($operationId);
        $capabilities->require([
            EnvironmentProviderCapability::ENVIRONMENT_MUTATION_ACQUIRE,
            EnvironmentProviderCapability::ENVIRONMENT_MUTATION_RELEASE,
            EnvironmentProviderCapability::ENVIRONMENT_SLEEP,
            EnvironmentProviderCapability::ENVIRONMENT_WAKE,
            EnvironmentProviderCapability::OPERATION_RECEIPTS,
        ], 'sleep and wake an isolated cloud preview');
        if (EnvironmentLifecycleCanon::encode($capabilities->pin())
            !== EnvironmentLifecycleCanon::encode($latest['receipt']['target_provider'])) {
            throw new \RuntimeException('preview sleep provider does not match the creation-time provider pin');
        }

        $owner = 'duo-env-sleep-' . $latest['receipt']['operation_id'];
        $acquireInput = self::sleepIdentityInput($identity) + ['mutation_owner' => $owner];
        self::recordSleepCycleEvent(
            $journal,
            $previewOperationId,
            'sleep-fence-acquire-intent',
            $operationId,
            ['input' => $acquireInput]
        );
        $events = $journal->events($previewOperationId);
        $acquired = self::sleepCycleEvent($events, 'sleep-fence-acquired', $operationId);
        if ($acquired === null) {
            $fence = $target->perform('mutation-acquire', $operationId, $acquireInput);
            self::assertSleepFence($fence, $identity, $owner, 'held');
            self::recordSleepCycleEvent(
                $journal,
                $previewOperationId,
                'sleep-fence-acquired',
                $operationId,
                ['result' => $fence]
            );
        } else {
            $fence = $acquired['result'];
            self::assertSleepFence($fence, $identity, $owner, 'held');
        }

        $transitionInput = self::sleepIdentityInput($identity) + self::sleepMutationInput($fence);
        if ($action === 'sleep') {
            self::recordSleepCycleEvent(
                $journal,
                $previewOperationId,
                'sleep-intent',
                $operationId,
                ['input' => $transitionInput]
            );
            $events = $journal->events($previewOperationId);
            $slept = self::sleepCycleEvent($events, 'slept', $operationId);
            if ($slept === null) {
                $result = $target->perform('sleep', $operationId, $transitionInput);
                self::assertSleepResult($result, $identity, 'asleep');
                self::recordSleepCycleEvent(
                    $journal,
                    $previewOperationId,
                    'slept',
                    $operationId,
                    ['result' => $result]
                );
            } else {
                $result = $slept['result'];
                self::assertSleepResult($result, $identity, 'asleep');
            }
            return self::sleepReceipt(
                $result,
                'sleep',
                $targetEnvironment,
                $previewOperationId,
                $operationId,
                $slept !== null
            );
        }

        self::recordSleepCycleEvent(
            $journal,
            $previewOperationId,
            'wake-intent',
            $operationId,
            ['input' => $transitionInput]
        );
        $events = $journal->events($previewOperationId);
        $woken = self::sleepCycleEvent($events, 'woken', $operationId);
        if ($woken === null) {
            $result = $target->perform('wake', $operationId, $transitionInput);
            self::assertSleepResult($result, $identity, 'awake');
            self::recordSleepCycleEvent(
                $journal,
                $previewOperationId,
                'woken',
                $operationId,
                ['result' => $result]
            );
        } else {
            $result = $woken['result'];
            self::assertSleepResult($result, $identity, 'awake');
        }
        self::recordSleepCycleEvent(
            $journal,
            $previewOperationId,
            'sleep-fence-release-intent',
            $operationId,
            ['input' => $transitionInput]
        );
        $events = $journal->events($previewOperationId);
        $released = self::sleepCycleEvent($events, 'sleep-fence-released', $operationId);
        if ($released === null) {
            $releasedFence = $target->perform('mutation-release', $operationId, $transitionInput);
            self::assertSleepFence($releasedFence, $identity, $owner, 'released');
            self::recordSleepCycleEvent(
                $journal,
                $previewOperationId,
                'sleep-fence-released',
                $operationId,
                ['result' => $releasedFence]
            );
        } else {
            self::assertSleepFence($released['result'], $identity, $owner, 'released');
        }
        return self::sleepReceipt(
            $result,
            'wake',
            $targetEnvironment,
            $previewOperationId,
            $operationId,
            $woken !== null
        );
    }

    /** @return array{events:list<array<string,mixed>>,operation_id:string,receipt:array<string,mixed>,run:array<string,mixed>} */
    private static function activeCompletedPreview(
        PreviewRunJournal $journal,
        string $targetEnvironment
    ): array {
        $latest = $journal->latestForTarget($targetEnvironment);
        if ($latest === null || PreviewRunJournal::eventData($latest['events'], 'reaped') !== null) {
            throw new \RuntimeException("target '$targetEnvironment' has no active universal preview");
        }
        $complete = PreviewRunJournal::eventData($latest['events'], 'complete');
        if ($complete === null) {
            throw new \RuntimeException('universal preview creation is incomplete; retry create before sleep/wake');
        }
        $receipt = self::completionReceipt($complete);
        if (!is_array($receipt['target_provider'] ?? null)
            || !is_string($receipt['operation_id'] ?? null)
            || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $receipt['operation_id']) !== 1) {
            throw new \RuntimeException('universal preview receipt has no lifecycle provider lineage');
        }
        return $latest + ['receipt' => $receipt];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private static function previewIdentity(array $receipt): array {
        $identity = [];
        foreach ([
            'environment_identity', 'lease_generation', 'lease_id',
            'ownership_receipt_sha256', 'resource_id', 'url',
        ] as $key) {
            if (!array_key_exists($key, $receipt)) {
                throw new \RuntimeException("universal preview receipt has no identity '$key'");
            }
            $identity[$key] = $receipt[$key];
        }
        foreach (['environment_identity', 'lease_id', 'resource_id'] as $key) {
            if (!is_string($identity[$key])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+-]{0,255}$/D', $identity[$key]) !== 1) {
                throw new \RuntimeException("universal preview identity '$key' is invalid");
            }
        }
        if (!is_int($identity['lease_generation']) || $identity['lease_generation'] < 1
            || !self::sha256($identity['ownership_receipt_sha256'])
            || !is_string($identity['url'])
            || filter_var($identity['url'], FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('universal preview identity is malformed');
        }
        return $identity;
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function sleepIdentityInput(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    /** @param array<string,mixed> $fence @return array<string,mixed> */
    private static function sleepMutationInput(array $fence): array {
        return [
            'expected_mutation_generation' => $fence['mutation_generation'],
            'expected_mutation_id' => $fence['mutation_id'],
            'expected_mutation_owner' => $fence['mutation_owner'],
            'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
        ];
    }

    /** @param list<array<string,mixed>> $events @return ?array<string,mixed> */
    private static function latestSleepCycle(array $events): ?array {
        $cycle = PreviewRunJournal::eventData($events, 'sleep-cycle-intent');
        if ($cycle === null) {
            return null;
        }
        self::exactKeys($cycle, ['cycle', 'identity', 'operation_id'], 'preview sleep cycle intent');
        if (!is_int($cycle['cycle']) || $cycle['cycle'] < 1
            || $cycle['cycle'] > self::MAX_SLEEP_CYCLES
            || !is_array($cycle['identity']) || array_is_list($cycle['identity'])
            || !is_string($cycle['operation_id'])
            || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $cycle['operation_id']) !== 1) {
            throw new \RuntimeException('preview sleep cycle intent is malformed');
        }
        self::previewIdentity($cycle['identity']);
        return $cycle;
    }

    /** @param list<array<string,mixed>> $events */
    private static function sleepCycleCount(array $events): int {
        $count = 0;
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'sleep-cycle-intent') {
                $count++;
            }
        }
        if ($count >= self::MAX_SLEEP_CYCLES) {
            throw new \RuntimeException('preview sleep cycle history is exhausted; reap the generation');
        }
        return $count;
    }

    /** @param list<array<string,mixed>> $events */
    private static function assertNoReapInProgress(array $events, string $targetEnvironment): void {
        $intent = PreviewRunJournal::eventData($events, 'reap-intent');
        if ($intent !== null) {
            self::exactKeys($intent, ['target_environment'], 'preview reap intent');
            if (($intent['target_environment'] ?? null) !== $targetEnvironment) {
                throw new \RuntimeException('preview reap intent is stale or foreign');
            }
            throw new \RuntimeException('preview reap is incomplete; retry `duo preview reap` exactly');
        }
        foreach ($events as $event) {
            $name = $event['event'] ?? null;
            if (is_string($name) && (str_starts_with($name, 'sleep-destroy-')
                || str_starts_with($name, 'sleep-reap-')
                || str_starts_with($name, 'candidate-reap-'))) {
                throw new \RuntimeException('preview reap is incomplete; retry `duo preview reap` exactly');
            }
        }
    }

    /** @param list<array<string,mixed>> $events @return ?array<string,mixed> */
    private static function sleepCycleEvent(array $events, string $name, string $operationId): ?array {
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) !== $name) {
                continue;
            }
            $data = $events[$index]['data'] ?? null;
            if (!is_array($data) || array_is_list($data)) {
                throw new \RuntimeException("preview sleep event '$name' is malformed");
            }
            if (($data['operation_id'] ?? null) === $operationId) {
                self::exactKeys($data, [
                    'operation_id', str_ends_with($name, 'intent') ? 'input' : 'result',
                ], "preview sleep event '$name'");
                $value = str_ends_with($name, 'intent') ? ($data['input'] ?? null) : ($data['result'] ?? null);
                if (!is_array($value) || array_is_list($value)) {
                    throw new \RuntimeException("preview sleep event '$name' has invalid evidence");
                }
                return $data;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $detail */
    private static function recordSleepCycleEvent(
        PreviewRunJournal $journal,
        string $previewOperationId,
        string $name,
        string $operationId,
        array $detail
    ): void {
        $data = ['operation_id' => $operationId] + $detail;
        $existing = self::sleepCycleEvent($journal->events($previewOperationId), $name, $operationId);
        if ($existing === null) {
            $journal->append($previewOperationId, $name, $data);
            return;
        }
        if (EnvironmentLifecycleCanon::encode($existing) !== EnvironmentLifecycleCanon::encode($data)) {
            throw new \RuntimeException("preview sleep recovery event '$name' changed");
        }
    }

    private static function sleepOperationId(
        string $previewOperationId,
        int $cycle,
        string $ownershipReceipt
    ): string {
        return substr($previewOperationId, 0, 15) . '-' . substr(hash(
            'sha256',
            "duo-cloud-preview-sleep-operation/v1\0$previewOperationId\0$cycle\0$ownershipReceipt"
        ), 0, 24);
    }

    private static function sleepReapOperationId(
        string $previewOperationId,
        string $sleepOperationId,
        string $ownershipReceipt
    ): string {
        return substr($previewOperationId, 0, 15) . '-' . substr(hash(
            'sha256',
            "duo-cloud-preview-sleep-reap-operation/v1\0$previewOperationId\0"
                . "$sleepOperationId\0$ownershipReceipt"
        ), 0, 24);
    }

    private static function sleepReapTerminalOperationId(
        string $previewOperationId,
        string $sleepOperationId,
        string $ownershipReceipt,
        string $stage
    ): string {
        if (!in_array($stage, [
            'sleep-fence-acquire', 'sleep-fence-release', 'sleep-reap-fence-acquire',
        ], true)) {
            throw new \RuntimeException('preview sleep reap terminal stage is invalid');
        }
        return substr($previewOperationId, 0, 15) . '-' . substr(hash(
            'sha256',
            "duo-cloud-preview-sleep-reap-terminal-operation/v1\0$previewOperationId\0"
                . "$sleepOperationId\0$ownershipReceipt\0$stage"
        ), 0, 24);
    }

    /** @param array<string,mixed> $fence @param array<string,mixed> $identity */
    private static function assertSleepFence(
        array $fence,
        array $identity,
        string $owner,
        string $state
    ): void {
        foreach ($identity as $key => $expected) {
            if (($fence[$key] ?? null) !== $expected) {
                throw new \RuntimeException("preview sleep fence changed generation identity '$key'");
            }
        }
        if (($fence['mutation_owner'] ?? null) !== $owner
            || ($fence['state'] ?? null) !== $state
            || !is_int($fence['mutation_generation'] ?? null)
            || $fence['mutation_generation'] < 1
            || !is_string($fence['mutation_id'] ?? null)
            || !self::sha256($fence['mutation_receipt_sha256'] ?? null)) {
            throw new \RuntimeException('preview sleep fence is stale, foreign, or in the wrong state');
        }
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $identity */
    private static function assertSleepResult(array $result, array $identity, string $state): void {
        foreach ($identity as $key => $expected) {
            if (($result[$key] ?? null) !== $expected) {
                throw new \RuntimeException("preview sleep transition changed generation identity '$key'");
            }
        }
        if (($result['sleep_state'] ?? null) !== $state
            || !is_array($result['_provider'] ?? null)
            || !self::sha256($result['_response_sha256'] ?? null)) {
            throw new \RuntimeException('preview sleep transition evidence is malformed');
        }
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function sleepReceipt(
        array $result,
        string $action,
        string $targetEnvironment,
        string $previewOperationId,
        string $operationId,
        bool $resumed
    ): array {
        $basis = [
            'action' => $action,
            'environment_identity' => $result['environment_identity'],
            'format' => self::SLEEP_RECEIPT_FORMAT,
            'lease_generation' => $result['lease_generation'],
            'lease_id' => $result['lease_id'],
            'operation_id' => $operationId,
            'ownership_receipt_sha256' => $result['ownership_receipt_sha256'],
            'preview_operation_id' => $previewOperationId,
            'provider' => $result['_provider'],
            'provider_response_sha256' => $result['_response_sha256'],
            'resource_id' => $result['resource_id'],
            'sleep_state' => $result['sleep_state'],
            'target_environment' => $targetEnvironment,
            'url' => $result['url'],
        ];
        return $basis + [
            'receipt_sha256' => hash('sha256', EnvironmentLifecycleCanon::encode($basis)),
            'resumed' => $resumed,
        ];
    }

    /**
     * A sleeping or interrupted sleep/wake cycle owns a held sleep fence that
     * the older materializer journal cannot name. Converge that exact fence
     * before the ordinary reap path inspects provider absence.
     *
     * @param array<string,mixed> $latest
     */
    private static function reconcileSleepForReap(
        CloudPreviewTransport $target,
        PreviewRunJournal $journal,
        array $latest,
        string $targetEnvironment
    ): void {
        $events = $latest['events'];
        $cycle = self::latestSleepCycle($events);
        if ($cycle === null) {
            return;
        }
        $operationId = $cycle['operation_id'];
        $released = self::sleepCycleEvent($events, 'sleep-fence-released', $operationId);
        $complete = PreviewRunJournal::eventData($events, 'complete');
        if ($complete === null) {
            throw new \RuntimeException('preview sleep cycle exists without a completed preview');
        }
        $receipt = self::completionReceipt($complete);
        $identity = self::previewIdentity($receipt);
        if (EnvironmentLifecycleCanon::encode($cycle['identity'])
            !== EnvironmentLifecycleCanon::encode($identity)) {
            throw new \RuntimeException('preview sleep reap cycle is stale or foreign');
        }
        $reapOperationId = self::sleepReapOperationId(
            $latest['operation_id'],
            $operationId,
            $identity['ownership_receipt_sha256']
        );
        $capabilities = $target->capabilities($reapOperationId);
        $capabilities->require([
            EnvironmentProviderCapability::ENVIRONMENT_DESTROY,
            EnvironmentProviderCapability::ENVIRONMENT_INSPECT,
            EnvironmentProviderCapability::ENVIRONMENT_MUTATION_ACQUIRE,
            EnvironmentProviderCapability::ENVIRONMENT_MUTATION_RELEASE,
            EnvironmentProviderCapability::OPERATION_RECEIPTS,
        ], 'reap a sleeping cloud preview');
        if (EnvironmentLifecycleCanon::encode($capabilities->pin())
            !== EnvironmentLifecycleCanon::encode($receipt['target_provider'])) {
            throw new \RuntimeException('preview sleep reap provider changed from its creation-time pin');
        }
        $owner = 'duo-env-sleep-' . $receipt['operation_id'];
        $reapOwner = 'duo-env-reap-' . $receipt['operation_id'];
        $sleepAcquireTerminalOperation = self::sleepReapTerminalOperationId(
            $latest['operation_id'],
            $operationId,
            $identity['ownership_receipt_sha256'],
            'sleep-fence-acquire'
        );
        $sleepReleaseTerminalOperation = self::sleepReapTerminalOperationId(
            $latest['operation_id'],
            $operationId,
            $identity['ownership_receipt_sha256'],
            'sleep-fence-release'
        );
        $reapAcquireTerminalOperation = self::sleepReapTerminalOperationId(
            $latest['operation_id'],
            $operationId,
            $identity['ownership_receipt_sha256'],
            'sleep-reap-fence-acquire'
        );
        if ($released === null) {
            $acquireInput = self::sleepIdentityInput($identity) + ['mutation_owner' => $owner];
            self::recordSleepCycleEvent(
                $journal,
                $latest['operation_id'],
                'sleep-fence-acquire-intent',
                $operationId,
                ['input' => $acquireInput]
            );
            $events = $journal->events($latest['operation_id']);
            $acquired = self::sleepCycleEvent($events, 'sleep-fence-acquired', $operationId);
            if ($acquired === null) {
                if (self::terminalSleepReapDestroyStarted(
                    $events,
                    $sleepAcquireTerminalOperation
                ) && self::tryTerminalSleepReapDestroy(
                    $target,
                    $journal,
                    $latest['operation_id'],
                    $sleepAcquireTerminalOperation,
                    $identity,
                    $reapOwner,
                    null
                )) {
                    return;
                }
                try {
                    $fence = $target->perform('mutation-acquire', $operationId, $acquireInput);
                } catch (\Throwable $failure) {
                    if (self::tryTerminalSleepReapDestroy(
                        $target,
                        $journal,
                        $latest['operation_id'],
                        $sleepAcquireTerminalOperation,
                        $identity,
                        $reapOwner,
                        null
                    )) {
                        return;
                    }
                    throw $failure;
                }
                self::assertSleepFence($fence, $identity, $owner, 'held');
                self::recordSleepCycleEvent(
                    $journal,
                    $latest['operation_id'],
                    'sleep-fence-acquired',
                    $operationId,
                    ['result' => $fence]
                );
            } else {
                $fence = $acquired['result'];
                self::assertSleepFence($fence, $identity, $owner, 'held');
            }

            $releaseIntent = self::sleepCycleEvent(
                $journal->events($latest['operation_id']),
                'sleep-fence-release-intent',
                $operationId
            );
            if ($releaseIntent === null) {
                self::destroySleepGeneration(
                    $target,
                    $journal,
                    $latest['operation_id'],
                    $operationId,
                    'sleep',
                    $identity,
                    $fence
                );
                return;
            }
            $releaseInput = self::sleepIdentityInput($identity) + self::sleepMutationInput($fence);
            if (EnvironmentLifecycleCanon::encode($releaseIntent['input'])
                !== EnvironmentLifecycleCanon::encode($releaseInput)) {
                throw new \RuntimeException('preview sleep fence release intent changed before reap');
            }
            $releaseEvents = $journal->events($latest['operation_id']);
            if (self::terminalSleepReapDestroyStarted(
                $releaseEvents,
                $sleepReleaseTerminalOperation
            ) && self::tryTerminalSleepReapDestroy(
                $target,
                $journal,
                $latest['operation_id'],
                $sleepReleaseTerminalOperation,
                $identity,
                $reapOwner,
                $fence
            )) {
                return;
            }
            try {
                $releasedFence = $target->perform('mutation-release', $operationId, $releaseInput);
            } catch (\Throwable $failure) {
                if (self::tryTerminalSleepReapDestroy(
                    $target,
                    $journal,
                    $latest['operation_id'],
                    $sleepReleaseTerminalOperation,
                    $identity,
                    $reapOwner,
                    $fence
                )) {
                    return;
                }
                throw $failure;
            }
            self::assertSleepFence($releasedFence, $identity, $owner, 'released');
            self::recordSleepCycleEvent(
                $journal,
                $latest['operation_id'],
                'sleep-fence-released',
                $operationId,
                ['result' => $releasedFence]
            );
            $released = ['operation_id' => $operationId, 'result' => $releasedFence];
        }
        self::assertSleepFence($released['result'], $identity, $owner, 'released');

        // Once a wake release is durable, the original materialization fence
        // is historical. Re-fence through the same acquisition lineage before
        // destruction; the ordinary reap then consumes terminal absence and
        // never attempts a second fence or destroy.
        $reapAcquireInput = self::sleepIdentityInput($identity) + [
            'mutation_owner' => $reapOwner,
        ];
        self::recordSleepCycleEvent(
            $journal,
            $latest['operation_id'],
            'sleep-reap-fence-acquire-intent',
            $reapOperationId,
            ['input' => $reapAcquireInput]
        );
        $events = $journal->events($latest['operation_id']);
        $reapAcquired = self::sleepCycleEvent(
            $events,
            'sleep-reap-fence-acquired',
            $reapOperationId
        );
        if ($reapAcquired === null) {
            if (self::terminalSleepReapDestroyStarted(
                $events,
                $reapAcquireTerminalOperation
            ) && self::tryTerminalSleepReapDestroy(
                $target,
                $journal,
                $latest['operation_id'],
                $reapAcquireTerminalOperation,
                $identity,
                $reapOwner,
                null
            )) {
                return;
            }
            try {
                $reapFence = $target->perform(
                    'mutation-acquire',
                    $reapOperationId,
                    $reapAcquireInput
                );
            } catch (\Throwable $failure) {
                if (self::tryTerminalSleepReapDestroy(
                    $target,
                    $journal,
                    $latest['operation_id'],
                    $reapAcquireTerminalOperation,
                    $identity,
                    $reapOwner,
                    null
                )) {
                    return;
                }
                throw $failure;
            }
            self::assertSleepFence($reapFence, $identity, $reapOwner, 'held');
            self::recordSleepCycleEvent(
                $journal,
                $latest['operation_id'],
                'sleep-reap-fence-acquired',
                $reapOperationId,
                ['result' => $reapFence]
            );
        } else {
            $reapFence = $reapAcquired['result'];
            self::assertSleepFence($reapFence, $identity, $reapOwner, 'held');
        }
        self::destroySleepGeneration(
            $target,
            $journal,
            $latest['operation_id'],
            $reapOperationId,
            'sleep-reap',
            $identity,
            $reapFence
        );
    }

    /** @param list<array<string,mixed>> $events */
    private static function terminalSleepReapDestroyStarted(
        array $events,
        string $operationId
    ): bool {
        return self::sleepCycleEvent(
            $events,
            'sleep-terminal-destroy-intent',
            $operationId
        ) !== null;
    }

    /**
     * A failed exact acquire has no fence receipt, so it cannot authorize a
     * destructive retry. This deliberately non-matching fence makes destroy a
     * monotonic terminal-absence probe: a present generation must refuse it,
     * while the service can sign the already-terminal identity after its TTL
     * janitor won. A real held fence is used when interrupted release recovery
     * still has that authority, allowing reap to converge directly.
     *
     * @param array<string,mixed> $identity
     * @param ?array<string,mixed> $heldFence
     */
    private static function tryTerminalSleepReapDestroy(
        CloudPreviewTransport $target,
        PreviewRunJournal $journal,
        string $previewOperationId,
        string $operationId,
        array $identity,
        string $reapOwner,
        ?array $heldFence
    ): bool {
        $fence = $heldFence;
        if ($fence === null) {
            $probeBasis = [
                'identity' => $identity,
                'operation_id' => $operationId,
                'owner' => $reapOwner,
            ];
            $probeSha256 = hash(
                'sha256',
                "duo-cloud-preview-terminal-absence-probe/v1\0"
                    . EnvironmentLifecycleCanon::encode($probeBasis)
            );
            $fence = [
                'mutation_generation' => 1,
                'mutation_id' => 'terminal-absence-probe-' . substr($probeSha256, 0, 32),
                'mutation_owner' => $reapOwner,
                'mutation_receipt_sha256' => $probeSha256,
            ];
        }
        $destroyInput = self::sleepIdentityInput($identity) + self::sleepMutationInput($fence)
            + ['compare_and_reap' => true];
        self::recordSleepCycleEvent(
            $journal,
            $previewOperationId,
            'sleep-terminal-destroy-intent',
            $operationId,
            ['input' => $destroyInput]
        );
        $destroyed = self::sleepCycleEvent(
            $journal->events($previewOperationId),
            'sleep-terminal-destroyed',
            $operationId
        );
        if ($destroyed !== null) {
            self::assertSleepDestroyResult($destroyed['result'], $identity);
            return true;
        }
        try {
            $result = $target->perform('destroy', $operationId, $destroyInput);
        } catch (\Throwable) {
            return false;
        }
        self::assertSleepDestroyResult($result, $identity);
        self::recordSleepCycleEvent(
            $journal,
            $previewOperationId,
            'sleep-terminal-destroyed',
            $operationId,
            ['result' => $result]
        );
        return true;
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $fence */
    private static function destroySleepGeneration(
        CloudPreviewTransport $target,
        PreviewRunJournal $journal,
        string $previewOperationId,
        string $operationId,
        string $eventPrefix,
        array $identity,
        array $fence
    ): void {
        $destroyInput = self::sleepIdentityInput($identity) + self::sleepMutationInput($fence)
            + ['compare_and_reap' => true];
        self::recordSleepCycleEvent(
            $journal,
            $previewOperationId,
            $eventPrefix . '-destroy-intent',
            $operationId,
            ['input' => $destroyInput]
        );
        $destroyed = self::sleepCycleEvent(
            $journal->events($previewOperationId),
            $eventPrefix . '-destroyed',
            $operationId
        );
        if ($destroyed === null) {
            $result = $target->perform('destroy', $operationId, $destroyInput);
            self::assertSleepDestroyResult($result, $identity);
            self::recordSleepCycleEvent(
                $journal,
                $previewOperationId,
                $eventPrefix . '-destroyed',
                $operationId,
                ['result' => $result]
            );
            return;
        }
        self::assertSleepDestroyResult($destroyed['result'], $identity);
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $identity */
    private static function assertSleepDestroyResult(array $result, array $identity): void {
        foreach ([
            'environment_identity', 'lease_generation', 'lease_id',
            'ownership_receipt_sha256', 'resource_id',
        ] as $key) {
            if (($result[$key] ?? null) !== $identity[$key]) {
                throw new \RuntimeException("sleeping preview reap changed generation identity '$key'");
            }
        }
        if (($result['disposition'] ?? null) !== 'destroyed') {
            throw new \RuntimeException('sleeping preview reap did not prove destruction');
        }
    }

    /** @return array<string,mixed> */
    private static function reap(
        string $root,
        CloudPreviewTransport $target,
        PreviewRunJournal $previewJournal,
        EnvironmentLifecycleJournal $lifecycleJournal,
        string $targetEnvironment
    ): array {
        $latest = $previewJournal->latestForTarget($targetEnvironment);
        if ($latest === null) {
            throw new \RuntimeException("target '$targetEnvironment' has no universal preview receipt");
        }
        $already = PreviewRunJournal::eventData($latest['events'], 'reaped');
        if ($already !== null) {
            return self::journaledReapReceipt($already);
        }

        self::recordOnce($previewJournal, $latest['operation_id'], 'reap-intent', [
            'target_environment' => $targetEnvironment,
        ]);

        self::reconcileSleepForReap(
            $target,
            $previewJournal,
            $latest,
            $targetEnvironment
        );

        $materializer = PreviewRunJournal::eventData(
            $latest['events'],
            'materializer-intent'
        );
        if ($materializer !== null) {
            self::exactKeys($materializer, ['intent_sha256'], 'preview materializer intent');
            if (!self::sha256($materializer['intent_sha256'])) {
                throw new \RuntimeException('preview materializer intent is malformed');
            }
        }
        $lifecycle = $lifecycleJournal->latestForTarget($targetEnvironment);
        $matches = $materializer !== null
            && $lifecycle !== null
            && strcmp($lifecycle['operation_id'], $latest['operation_id']) >= 0
            && ($lifecycle['run']['source_environment'] ?? null) === 'portable-origin-export'
            && ($lifecycle['run']['intent_sha256'] ?? null) === $materializer['intent_sha256'];
        if ($matches) {
            self::cleanupCandidateForReap(
                $root,
                $previewJournal,
                $lifecycleJournal,
                $latest['operation_id'],
                $lifecycle['operation_id']
            );
            $receipt = PortablePreviewMaterializer::reap(
                $target,
                $target,
                $lifecycleJournal,
                $lifecycle['operation_id']
            );
        } elseif ($materializer === null) {
            $foreignCurrent = $lifecycle !== null
                && strcmp($lifecycle['operation_id'], $latest['operation_id']) >= 0
                && PreviewRunJournal::eventData($lifecycle['events'], 'reaped') === null;
            if ($foreignCurrent) {
                throw new \RuntimeException(
                    'the current target lifecycle does not match this universal preview intent'
                );
            }
            $receipt = self::localAbsenceReceipt(
                $latest['operation_id'],
                $targetEnvironment,
                $materializer['intent_sha256'] ?? null
            );
        } else {
            throw new \RuntimeException(
                'the universal preview materializer intent has no matching lifecycle authority'
            );
        }
        $previewJournal->append($latest['operation_id'], 'reaped', ['receipt' => $receipt]);
        return $receipt;
    }

    /**
     * A candidate push can succeed before its response or publication receipt
     * is durable. Reap therefore treats the earlier signed publication intent
     * as authority, deletes only an exact matching operation ref, and records
     * remote absence before the provider generation may become terminal.
     */
    private static function cleanupCandidateForReap(
        string $root,
        PreviewRunJournal $previewJournal,
        EnvironmentLifecycleJournal $lifecycleJournal,
        string $previewOperationId,
        string $lifecycleOperationId
    ): void {
        $events = $lifecycleJournal->events($lifecycleOperationId);
        $publicationIntent = self::environmentEventData(
            $events,
            'candidate-publication-intent'
        );
        if ($publicationIntent === null) {
            return;
        }
        self::exactKeys(
            $publicationIntent,
            ['action', 'input', 'input_sha256'],
            'cloud candidate publication intent'
        );
        $expected = $publicationIntent['input'] ?? null;
        if (($publicationIntent['action'] ?? null) !== 'candidate-publication'
            || !is_array($expected)
            || array_is_list($expected)
            || !self::sha256($publicationIntent['input_sha256'] ?? null)
            || !hash_equals(
                (string) $publicationIntent['input_sha256'],
                hash('sha256', EnvironmentLifecycleCanon::encode($expected))
            )) {
            throw new \RuntimeException('cloud candidate publication intent does not verify');
        }
        self::assertExpectedPublication($expected);
        if (($expected['operation_id'] ?? null) !== $lifecycleOperationId) {
            throw new \RuntimeException(
                'cloud candidate publication intent is not bound to its lifecycle operation'
            );
        }
        $publication = self::environmentEventData($events, 'candidate-published');
        if ($publication !== null) {
            self::assertPublicationReceipt($publication, $expected);
        }

        $intent = [
            'branch_commit' => $expected['branch_commit'],
            'branch_ref' => $expected['branch_ref'],
            'format' => self::REAP_CLEANUP_FORMAT,
            'lifecycle_operation_id' => $lifecycleOperationId,
            'operation_id' => $previewOperationId,
            'publication_input_sha256' => $publicationIntent['input_sha256'],
            'remote_url_sha256' => $expected['remote_url_sha256'],
            'status' => 'cleanup-intent',
        ];
        self::recordOnce(
            $previewJournal,
            $previewOperationId,
            'candidate-reap-cleanup-intent',
            $intent
        );

        [$remote] = self::matchingRemote($root, $expected['remote_url_sha256']);
        $remoteHead = self::remoteHead($root, $remote, $expected['branch_ref']);
        if ($remoteHead !== null) {
            if (!hash_equals($expected['branch_commit'], $remoteHead)) {
                throw new \RuntimeException(
                    'cloud preview operation ref changed before exact reap cleanup'
                );
            }
            self::gitRedacted($root, [
                '-c', 'core.hooksPath=/dev/null',
                'push', '--porcelain',
                '--force-with-lease=' . $expected['branch_ref'] . ':' . $expected['branch_commit'],
                $remote, ':' . $expected['branch_ref'],
            ], 'remove the cloud preview operation ref during reap');
        }
        if (self::remoteHead($root, $remote, $expected['branch_ref']) !== null) {
            throw new \RuntimeException(
                'cloud preview reap cleanup did not prove remote candidate absence'
            );
        }

        $basis = [
            'branch_commit' => $expected['branch_commit'],
            'branch_ref' => $expected['branch_ref'],
            'format' => self::REAP_CLEANUP_FORMAT,
            'lifecycle_operation_id' => $lifecycleOperationId,
            'operation_id' => $previewOperationId,
            'publication_input_sha256' => $publicationIntent['input_sha256'],
            'status' => 'absent',
        ];
        $cleaned = $basis + ['cleanup_receipt_sha256' => hash(
            'sha256',
            self::REAP_CLEANUP_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($basis)
        )];
        self::recordOnce(
            $previewJournal,
            $previewOperationId,
            'candidate-reap-cleaned',
            $cleaned
        );
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return ?array<string,mixed>
     */
    private static function environmentEventData(array $events, string $event): ?array {
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) !== $event) {
                continue;
            }
            $data = $events[$index]['data'] ?? null;
            if (!is_array($data) || array_is_list($data)) {
                throw new \RuntimeException("environment event '$event' has malformed data");
            }
            return $data;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function localAbsenceReceipt(
        string $operationId,
        string $targetEnvironment,
        ?string $materializerIntentSha256
    ): array {
        $absence = [
            'materializer_intent_sha256' => $materializerIntentSha256,
            'operation_id' => $operationId,
            'status' => 'not-created',
            'target_environment' => $targetEnvironment,
        ];
        $receipt = [
            'absence_proof_sha256' => hash(
                'sha256',
                self::LOCAL_REAP_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($absence)
            ),
            'disposition' => 'not-created',
            'format' => self::LOCAL_REAP_FORMAT,
            'operation_id' => $operationId,
            'target_environment' => $targetEnvironment,
        ];
        $receipt['receipt_sha256'] = hash(
            'sha256',
            EnvironmentLifecycleCanon::encode($receipt)
        );
        return $receipt + ['resumed' => false];
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private static function journaledReapReceipt(array $event): array {
        self::exactKeys($event, ['receipt'], 'preview reap event');
        $receipt = $event['receipt'];
        if (!is_array($receipt) || array_is_list($receipt)
            || !in_array($receipt['format'] ?? null, [
                'duo-branch-environment-reap/v1', self::LOCAL_REAP_FORMAT,
            ], true)
            || !in_array($receipt['disposition'] ?? null, [
                'already-absent', 'destroyed', 'detached', 'not-created',
            ], true)
            || !is_bool($receipt['resumed'] ?? null)
            || !self::sha256($receipt['absence_proof_sha256'] ?? null)
            || !self::sha256($receipt['receipt_sha256'] ?? null)) {
            throw new \RuntimeException('journaled preview reap receipt is malformed');
        }
        $basis = $receipt;
        $claimed = $basis['receipt_sha256'];
        unset($basis['receipt_sha256'], $basis['resumed']);
        if (!hash_equals($claimed, hash('sha256', EnvironmentLifecycleCanon::encode($basis)))) {
            throw new \RuntimeException('journaled preview reap receipt does not verify');
        }
        $receipt['resumed'] = true;
        return $receipt;
    }

    /**
     * @param array<string,mixed> $demand
     * @return array<string,mixed>
     */
    private static function committedStatus(
        CloudOriginExportClient $client,
        array $demand,
        string $operationId,
        PreviewRunJournal $journal,
        string $originEnvironment,
        ?string $envsFileOverride,
        string $startDirectory
    ): array {
        for ($attempt = 0; $attempt <= self::MAX_ORIGIN_DRIVES_PER_INVOCATION; $attempt++) {
            $events = $journal->events($operationId);
            $sequence = self::nextPollSequence($events);
            $intent = self::sequencedEvent($events, 'origin-poll-intent', $sequence);
            if ($intent === null) {
                $journal->append($operationId, 'origin-poll-intent', [
                    'poll_sequence' => $sequence,
                ]);
            }
            $result = self::sequencedEvent(
                $journal->events($operationId),
                'origin-polled',
                $sequence
            );
            if ($result === null) {
                $status = $client->pollPortableExport($demand, $operationId, $sequence);
                $journal->append($operationId, 'origin-polled', [
                    'poll_sequence' => $sequence,
                    'status' => $status,
                ]);
            } else {
                $status = $result['status'] ?? null;
                if (!is_array($status) || array_is_list($status)) {
                    throw new \RuntimeException('journaled cloud-origin poll is malformed');
                }
            }
            if (($status['state'] ?? null) === 'committed') {
                return $status;
            }
            if (in_array($status['state'] ?? null, ['expired', 'revoked'], true)) {
                throw new \RuntimeException(
                    'cloud-origin export demand became ' . $status['state'] . '; start a new preview after exact reap'
                );
            }
            if ($attempt === self::MAX_ORIGIN_DRIVES_PER_INVOCATION) {
                break;
            }
            $driveSequence = self::nextDriveSequence($journal->events($operationId));
            $trigger = self::sequencedEvent(
                $journal->events($operationId),
                'origin-triggered',
                $driveSequence
            );
            if ($trigger === null) {
                self::recordSequencedIntent(
                    $journal,
                    $operationId,
                    'origin-trigger-intent',
                    'drive_sequence',
                    $driveSequence
                );
                $upload = OriginCommand::exportOnce(
                    $originEnvironment,
                    $envsFileOverride,
                    $startDirectory
                );
                $journal->append($operationId, 'origin-triggered', [
                    'drive_sequence' => $driveSequence,
                    'upload' => $upload,
                ]);
            }
        }
        throw new \RuntimeException(
            'cloud-origin export did not commit within the bounded controller drive; retry the same preview intent'
        );
    }

    /**
     * @param array<string,mixed> $intent
     * @return array{head:string,new_branch:string,refresh_run_id:string}
     */
    private static function candidate(
        string $root,
        array $intent,
        CloudCommittedOriginExport $export,
        string $operationId,
        PreviewRunJournal $journal
    ): array {
        $existing = PreviewRunJournal::eventData($journal->events($operationId), 'candidate-created');
        if ($existing !== null) {
            self::assertCandidate($root, $intent, $existing);
            return $existing;
        }
        $recovered = self::recoverRefreshCandidate($root, $intent, $export);
        if ($recovered !== null) {
            $journal->append($operationId, 'candidate-created', $recovered);
            return $recovered;
        }
        $result = Refresh::rebaseProductionSource(
            $export,
            $intent['production_ref'],
            $intent['candidate_branch']
        );
        $candidate = [
            'head' => $result['head'],
            'new_branch' => $result['new_branch'],
            'refresh_run_id' => $result['run_id'],
        ];
        self::assertCandidate($root, $intent, $candidate);
        $journal->append($operationId, 'candidate-created', $candidate);
        return $candidate;
    }

    /**
     * @return array<string,mixed>
     */
    private static function publishCandidate(
        string $root,
        array $expected,
        ?array $prior
    ): array {
        self::assertExpectedPublication($expected);
        if ($prior !== null) {
            self::assertPublicationReceipt($prior, $expected);
        }
        [$remote] = self::matchingRemote($root, $expected['remote_url_sha256']);
        $remoteHead = self::remoteHead($root, $remote, $expected['branch_ref']);
        if ($remoteHead !== null && !hash_equals($expected['branch_commit'], $remoteHead)) {
            throw new \RuntimeException(
                'the deterministic cloud preview operation ref already names another commit'
            );
        }
        if ($prior !== null && $remoteHead === null) {
            throw new \RuntimeException(
                'journaled cloud preview candidate publication is absent from its signed remote'
            );
        }
        if ($prior === null && $remoteHead === null) {
            self::gitRedacted($root, [
                '-c', 'core.hooksPath=/dev/null',
                'push', '--porcelain', $remote,
                $expected['branch_commit'] . ':' . $expected['branch_ref'],
            ], 'publish the cloud preview candidate');
        }
        $readback = self::remoteHead($root, $remote, $expected['branch_ref']);
        if ($readback === null || !hash_equals($expected['branch_commit'], $readback)) {
            throw new \RuntimeException('cloud preview candidate remote readback did not match its exact commit');
        }
        if ($prior !== null) {
            return $prior;
        }
        $publication = $expected;
        $publication['publication_receipt_sha256'] = hash(
            'sha256',
            self::PUBLICATION_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($expected)
        );
        return $publication;
    }

    /**
     * Delete only the exact operation ref after the held-fence service sync
     * has durably copied it. An exact lease prevents a concurrent ref change
     * from being erased; readback proves the temporary object root is absent.
     *
     * @param array<string,mixed> $publication
     * @param array<string,mixed> $repositorySync
     * @param ?array<string,mixed> $prior
     * @return array<string,mixed>
     */
    private static function cleanupCandidate(
        string $root,
        array $publication,
        array $repositorySync,
        ?array $prior
    ): array {
        $expectedPublication = $publication;
        unset($expectedPublication['publication_receipt_sha256']);
        self::assertExpectedPublication($expectedPublication);
        self::assertPublicationReceipt($publication, $expectedPublication);
        if (($repositorySync['branch_commit'] ?? null) !== $publication['branch_commit']
            || ($repositorySync['branch_ref'] ?? null) !== $publication['branch_ref']
            || ($repositorySync['candidate_publication_receipt_sha256'] ?? null)
                !== $publication['publication_receipt_sha256']
            || ($repositorySync['repository_authority_sha256'] ?? null)
                !== $publication['repository_authority_sha256']
            || !self::sha256($repositorySync['repository_sync_receipt_sha256'] ?? null)) {
            throw new \RuntimeException(
                'cloud repository sync is not bound to the exact candidate publication'
            );
        }
        $basis = [
            'branch_commit' => $publication['branch_commit'],
            'branch_ref' => $publication['branch_ref'],
            'candidate_publication_receipt_sha256' => $publication['publication_receipt_sha256'],
            'format' => self::CLEANUP_FORMAT,
            'operation_id' => $publication['operation_id'],
            'repository_sync_receipt_sha256' => $repositorySync['repository_sync_receipt_sha256'],
            'status' => 'absent',
        ];
        $cleanup = $basis + ['cleanup_receipt_sha256' => hash(
            'sha256',
            self::CLEANUP_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($basis)
        )];
        if ($prior !== null) {
            self::assertCleanupReceipt($prior, $basis);
            if (EnvironmentLifecycleCanon::encode($prior)
                !== EnvironmentLifecycleCanon::encode($cleanup)) {
                throw new \RuntimeException('journaled candidate cleanup receipt changed on recovery');
            }
        }

        [$remote] = self::matchingRemote($root, $publication['remote_url_sha256']);
        $remoteHead = self::remoteHead($root, $remote, $publication['branch_ref']);
        if ($prior !== null && $remoteHead !== null) {
            throw new \RuntimeException(
                'journaled cloud preview candidate cleanup no longer proves remote absence'
            );
        }
        if ($prior === null && $remoteHead !== null) {
            if (!hash_equals($publication['branch_commit'], $remoteHead)) {
                throw new \RuntimeException(
                    'cloud preview operation ref changed before exact candidate cleanup'
                );
            }
            self::gitRedacted($root, [
                '-c', 'core.hooksPath=/dev/null',
                'push', '--porcelain',
                '--force-with-lease=' . $publication['branch_ref'] . ':' . $publication['branch_commit'],
                $remote, ':' . $publication['branch_ref'],
            ], 'remove the synced cloud preview operation ref');
        }
        if (self::remoteHead($root, $remote, $publication['branch_ref']) !== null) {
            throw new \RuntimeException('cloud preview candidate cleanup did not prove remote absence');
        }
        return $prior ?? $cleanup;
    }

    /** @param array<string,mixed> $expected */
    private static function assertExpectedPublication(array $expected): void {
        self::exactKeys($expected, [
            'branch_commit', 'branch_ref', 'credential_helper_sha256', 'format',
            'operation_id', 'remote_url_sha256', 'repository_authority_sha256', 'status',
        ], 'cloud candidate publication expectation');
        if ($expected['format'] !== self::PUBLICATION_FORMAT
            || $expected['status'] !== 'published'
            || !self::gitOid($expected['branch_commit'])
            || !is_string($expected['branch_ref'])
            || preg_match('#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $expected['branch_ref']) !== 1
            || str_contains($expected['branch_ref'], '..')
            || str_contains($expected['branch_ref'], '//')
            || str_contains($expected['branch_ref'], '@{')
            || str_ends_with($expected['branch_ref'], '.lock')
            || !is_string($expected['operation_id'])
            || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $expected['operation_id']) !== 1
            || !str_ends_with($expected['branch_ref'], '/' . $expected['operation_id'])
            || !self::sha256($expected['credential_helper_sha256'])
            || !self::sha256($expected['remote_url_sha256'])
            || !self::sha256($expected['repository_authority_sha256'])) {
            throw new \RuntimeException('cloud candidate publication expectation is malformed');
        }
    }

    /** @param array<string,mixed> $publication @param array<string,mixed> $expected */
    private static function assertPublicationReceipt(array $publication, array $expected): void {
        self::exactKeys($publication, array_merge(
            array_keys($expected),
            ['publication_receipt_sha256']
        ), 'cloud candidate publication receipt');
        $basis = $publication;
        $claimed = $basis['publication_receipt_sha256'] ?? null;
        unset($basis['publication_receipt_sha256']);
        if (EnvironmentLifecycleCanon::encode($basis) !== EnvironmentLifecycleCanon::encode($expected)
            || !self::sha256($claimed)
            || !hash_equals(
                $claimed,
                hash(
                    'sha256',
                    self::PUBLICATION_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($basis)
                )
            )) {
            throw new \RuntimeException('cloud candidate publication receipt does not verify');
        }
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $basis */
    private static function assertCleanupReceipt(array $receipt, array $basis): void {
        self::exactKeys($receipt, array_merge(array_keys($basis), ['cleanup_receipt_sha256']),
            'cloud candidate cleanup receipt');
        $claimed = $receipt['cleanup_receipt_sha256'] ?? null;
        $receiptBasis = $receipt;
        unset($receiptBasis['cleanup_receipt_sha256']);
        if (EnvironmentLifecycleCanon::encode($receiptBasis)
                !== EnvironmentLifecycleCanon::encode($basis)
            || !self::sha256($claimed)
            || !hash_equals(
                $claimed,
                hash(
                    'sha256',
                    self::CLEANUP_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($receiptBasis)
                )
            )) {
            throw new \RuntimeException('cloud candidate cleanup receipt does not verify');
        }
    }

    /** @param array<string,mixed> $observation */
    private static function assertObservation(
        array $observation,
        EnvironmentDriver $driver
    ): void {
        self::exactKeys($observation, [
            'format', 'plan_sha256', 'receipt_sha256', 'status', 'target_driver_id',
            'target_environment',
        ], 'portable preview observation');
        $basis = $observation;
        $claimed = $basis['receipt_sha256'] ?? null;
        unset($basis['receipt_sha256']);
        if ($observation['format'] !== self::OBSERVATION_FORMAT
            || $observation['status'] !== 'converged'
            || $observation['target_driver_id'] !== $driver->driverId()
            || $observation['target_environment'] !== $driver->name()
            || !self::sha256($observation['plan_sha256'])
            || !self::sha256($claimed)
            || !hash_equals(
                $claimed,
                hash(
                    'sha256',
                    self::OBSERVATION_FORMAT . "\0" . EnvironmentLifecycleCanon::encode($basis)
                )
            )) {
            throw new \RuntimeException('portable preview convergence observation does not verify');
        }
    }

    /** @param array<string,mixed> $authority */
    private static function assertRepositoryAuthority(array $authority): void {
        self::exactKeys($authority, [
            'credential_helper_sha256', 'descriptor_sha256', 'format', 'ref_prefix',
            'remote_url_sha256',
        ], 'cloud repository authority');
        if ($authority['format'] !== 'duo-cloud-repository-authority/v1'
            || !self::sha256($authority['credential_helper_sha256'])
            || !self::sha256($authority['descriptor_sha256'])
            || !self::sha256($authority['remote_url_sha256'])
            || !is_string($authority['ref_prefix'])
            || strlen($authority['ref_prefix']) > 384
            || preg_match('#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*/$#D', $authority['ref_prefix']) !== 1
            || str_contains($authority['ref_prefix'], '..')
            || str_contains($authority['ref_prefix'], '//')
            || str_contains($authority['ref_prefix'], '@{')
            || str_contains($authority['ref_prefix'], '.lock/')) {
            throw new \RuntimeException('cloud repository authority is malformed');
        }
        $basis = $authority;
        unset($basis['descriptor_sha256']);
        if (!hash_equals(
            $authority['descriptor_sha256'],
            hash(
                'sha256',
                'duo-cloud-repository-authority/v1' . "\0"
                    . EnvironmentLifecycleCanon::encode($basis)
            )
        )) {
            throw new \RuntimeException('cloud repository authority descriptor hash does not verify');
        }
    }

    /**
     * Map the portable owner onto the existing frozen-promotion transaction,
     * then wrap its independently verified branch-environment receipt. The
     * ordinary deploy/lifecycle/apply implementation remains the only mutation
     * path; this adapter adds only portable export/base bindings.
     *
     * @param array<string,mixed> $frozen
     * @param callable(EnvironmentDriver,array<string,mixed>):(array<string,mixed>|int) $promote
     * @return array<string,mixed>
     */
    private static function promotePortable(
        EnvironmentDriver $driver,
        array $frozen,
        callable $promote
    ): array {
        $operationId = $frozen['operation_id'] ?? null;
        if (!is_string($operationId)
            || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $operationId) !== 1
            || ($frozen['promotion_owner'] ?? null) !== 'duo-portable-promotion-' . $operationId) {
            throw new \RuntimeException('portable promotion owner is not bound to its operation');
        }
        $repo = rtrim($driver->repoPath(), '/');
        $artifact = $repo . '/.duo/artifacts/materialize-' . $operationId . '.json';
        $checkpoint = $repo . '/.duo/checkpoints/materialize-' . $operationId . '.sql';
        $directories = $driver->captureRaw(
            'mkdir -p ' . escapeshellarg(dirname($artifact)) . ' ' . escapeshellarg(dirname($checkpoint))
        );
        if (($directories['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('portable promotion could not create operation-owned target directories');
        }
        $compile = CodeDeploy::compile($driver, $repo, $artifact);
        $summary = $compile['summary'] ?? null;
        if (($compile['exit'] ?? 1) !== 0 || !is_array($summary) || array_is_list($summary)
            || !CodeDeploy::validArtifactHash($summary)
            || !self::sha256($summary['revision_hash'] ?? null)) {
            throw new \RuntimeException('portable candidate compilation did not produce a frozen artifact');
        }
        $ordinaryContext = [
            'artifact_path' => $artifact,
            'checkpoint_path' => $checkpoint,
            'compiled_summary' => $summary,
            'operation_id' => $operationId,
            'promotion_owner' => 'duo-env-promotion-' . $operationId,
        ];
        $ordinary = $promote($driver, $ordinaryContext);
        if (!is_array($ordinary) || array_is_list($ordinary)) {
            throw new \RuntimeException('frozen branch-environment promotion returned no receipt');
        }
        self::exactKeys($ordinary, [
            'artifact_hash', 'checkpoint_identity', 'code_revision', 'format', 'operation_id',
            'owner', 'receipt_sha256', 'state_revision', 'status',
        ], 'frozen branch-environment promotion receipt');
        $ordinaryBasis = $ordinary;
        unset($ordinaryBasis['receipt_sha256']);
        if ($ordinary['format'] !== 'duo-branch-environment-promotion-receipt/v1'
            || $ordinary['status'] !== 'completed'
            || $ordinary['operation_id'] !== $operationId
            || $ordinary['owner'] !== $ordinaryContext['promotion_owner']
            || $ordinary['artifact_hash'] !== $summary['artifact_hash']
            || $ordinary['state_revision'] !== $summary['revision_hash']
            || !self::sha256($ordinary['checkpoint_identity'])
            || !self::sha256($ordinary['receipt_sha256'])
            || !hash_equals(
                $ordinary['receipt_sha256'],
                hash('sha256', EnvironmentLifecycleCanon::encode($ordinaryBasis))
            )) {
            throw new \RuntimeException('frozen branch-environment promotion receipt does not verify');
        }
        $export = $frozen['portable_export'] ?? null;
        $base = $frozen['reviewed_base'] ?? null;
        $containment = $frozen['reviewed_base_containment'] ?? null;
        if (!is_array($export) || !is_array($base) || !is_array($containment)) {
            throw new \RuntimeException('portable promotion frozen evidence is malformed');
        }
        $receipt = [
            'base_containment_descriptor_sha256' => $containment['descriptor_sha256'],
            'base_image_digest' => $base['image_digest'],
            'base_platform_fingerprint_sha256' => $base['platform_fingerprint_sha256'],
            'base_review_receipt_sha256' => $base['review_receipt_sha256'],
            'branch_commit' => $frozen['branch_commit'],
            'candidate_cleanup_receipt_sha256' =>
                $frozen['candidate_cleanup_receipt_sha256'],
            'candidate_publication_receipt_sha256' =>
                $frozen['candidate_publication_receipt_sha256'],
            'export_manifest_sha256' => $export['manifest_sha256'],
            'export_snapshot_hash' => $export['snapshot_hash'],
            'format' => PortablePreviewMaterializer::PROMOTION_RECEIPT_FORMAT,
            'operation_id' => $operationId,
            'owner' => $frozen['promotion_owner'],
            'repository_authority_sha256' =>
                $frozen['repository_authority']['descriptor_sha256'],
            'repository_credential_helper_sha256' =>
                $frozen['repository_authority']['credential_helper_sha256'],
            'repository_receipt_sha256' => $frozen['repository_receipt_sha256'],
            'repository_sync_receipt_sha256' => $frozen['repository_sync_receipt_sha256'],
            'state_revision' => $ordinary['state_revision'],
            'status' => 'completed',
        ];
        $receipt['receipt_sha256'] = hash(
            'sha256',
            EnvironmentLifecycleCanon::encode($receipt)
        );
        return $receipt;
    }

    /** @return array{0:string,1:string} */
    private static function matchingRemote(string $root, string $expectedHash): array {
        $names = preg_split('/\r?\n/', self::gitStdout($root, ['remote'])) ?: [];
        $matches = [];
        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $name) !== 1) {
                continue;
            }
            $pushUrls = self::gitConfigValues($root, 'remote.' . $name . '.pushurl');
            $urls = $pushUrls === []
                ? self::gitConfigValues($root, 'remote.' . $name . '.url')
                : $pushUrls;
            $urls = array_values(array_filter($urls, static fn(string $url): bool => $url !== ''));
            if (count($urls) !== 1) {
                continue;
            }
            try {
                $canonical = self::canonicalRemoteUrl($urls[0]);
            } catch (\RuntimeException) {
                continue;
            }
            $hash = hash('sha256', "duo-cloud-repository-remote-url/v1\0" . $canonical);
            if (hash_equals($expectedHash, $hash)) {
                $effective = self::gitProcess(
                    $root,
                    ['remote', 'get-url', '--push', '--all', '--', $name]
                );
                $effectiveUrls = $effective['exit'] === 0
                    ? (preg_split('/\r?\n/', trim($effective['stdout'])) ?: [])
                    : [];
                if ($effectiveUrls !== [$canonical]) {
                    throw new \RuntimeException(
                        'site-repository remote is rewritten away from the signed cloud authority'
                    );
                }
                $matches[] = [$name, $hash];
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException(
                count($matches) === 0
                    ? 'no credential-free site-repository remote matches the signed cloud authority'
                    : 'multiple site-repository remotes match the signed cloud authority'
            );
        }
        return $matches[0];
    }

    private static function canonicalRemoteUrl(string $url): string {
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 2048
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower((string) $parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') === '' || ($parts['path'] ?? '') === '/') {
            throw new \RuntimeException('site-repository remote must be canonical credential-free HTTPS');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $canonical = 'https://' . $host;
        if (isset($parts['port']) && $parts['port'] !== 443) {
            $canonical .= ':' . $parts['port'];
        }
        $canonical .= $path;
        if ($canonical !== $url) {
            throw new \RuntimeException('site-repository remote must be canonical credential-free HTTPS');
        }
        return $canonical;
    }

    /** @return list<string> */
    private static function gitConfigValues(string $root, string $key): array {
        $result = self::gitProcess($root, ['config', '--get-all', '--', $key]);
        if ($result['exit'] === 1 && trim($result['stdout']) === '') {
            return [];
        }
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Git could not read the site-repository remote authority');
        }
        return preg_split('/\r?\n/', trim($result['stdout'])) ?: [];
    }

    private static function remoteHead(string $root, string $remote, string $ref): ?string {
        $output = self::gitRedacted(
            $root,
            ['ls-remote', '--refs', $remote, $ref],
            'read back the cloud preview operation ref'
        );
        if (trim($output) === '') {
            return null;
        }
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        if (count($lines) !== 1
            || preg_match('/^([a-f0-9]{40}(?:[a-f0-9]{24})?)\t(.+)$/D', $lines[0], $match) !== 1
            || $match[2] !== $ref) {
            throw new \RuntimeException('cloud preview operation-ref readback is ambiguous');
        }
        return $match[1];
    }

    /** @param array<string,mixed> $intent */
    private static function assertSourceIdentity(string $root, array $intent): void {
        if (self::gitStdout($root, ['symbolic-ref', '--short', 'HEAD']) !== $intent['source_branch']
            || self::gitStdout($root, ['rev-parse', '--verify', 'HEAD^{commit}']) !== $intent['source_commit']
            || self::gitStdout($root, ['rev-parse', '--verify', $intent['production_ref'] . '^{commit}'])
                !== $intent['production_commit']
            || self::gitStdout($root, ['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
            throw new \RuntimeException(
                'source checkout, production ref, or worktree cleanliness changed during preview orchestration'
            );
        }
    }

    /** @return array{production_commit:string,source_branch:string,source_commit:string} */
    private static function sourceIdentity(string $root, string $productionRef, string $candidate): array {
        self::gitStdout($root, ['check-ref-format', '--branch', $candidate]);
        $sourceBranch = self::gitStdout($root, ['symbolic-ref', '--short', 'HEAD']);
        if ($sourceBranch === '' || self::gitStdout($root, ['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
            throw new \RuntimeException('preview requires a clean attached source branch');
        }
        return [
            'production_commit' => self::gitStdout($root, ['rev-parse', '--verify', $productionRef . '^{commit}']),
            'source_branch' => $sourceBranch,
            'source_commit' => self::gitStdout($root, ['rev-parse', '--verify', 'HEAD^{commit}']),
        ];
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $candidate */
    private static function assertCandidate(string $root, array $intent, array $candidate): void {
        self::exactKeys($candidate, ['head', 'new_branch', 'refresh_run_id'], 'preview candidate');
        if ($candidate['new_branch'] !== $intent['candidate_branch']
            || !self::gitOid($candidate['head'])
            || !is_string($candidate['refresh_run_id'])
            || self::gitStdout(
                $root,
                ['rev-parse', '--verify', 'refs/heads/' . $intent['candidate_branch'] . '^{commit}']
            ) !== $candidate['head']) {
            throw new \RuntimeException('semantic refresh candidate does not match its exact local branch ref');
        }
    }

    /** @param array<string,mixed> $intent @return ?array{head:string,new_branch:string,refresh_run_id:string} */
    private static function recoverRefreshCandidate(
        string $root,
        array $intent,
        CloudCommittedOriginExport $export
    ): ?array {
        $productionSnapshotHash = $export->manifest()['snapshot_hash'] ?? null;
        if (!self::sha256($productionSnapshotHash)) {
            throw new \RuntimeException('verified cloud origin export has no production snapshot identity');
        }
        $common = self::gitCommonDir($root);
        $paths = glob($common . '/duo-refresh/runs/*/run.json') ?: [];
        rsort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $run = json_decode((string) @file_get_contents($path), true);
            if (!is_array($run) || ($run['kind'] ?? null) !== 'rebase'
                || ($run['new_branch'] ?? null) !== $intent['candidate_branch']
                || ($run['source_head'] ?? null) !== $intent['source_commit']
                || ($run['production_commit'] ?? null) !== $intent['production_commit']
                || ($run['production_env'] ?? null) !== $export->productionEnvironmentName()
                || ($run['production_snapshot_hash'] ?? null) !== $productionSnapshotHash) {
                continue;
            }
            $events = glob(dirname($path) . '/events/*.json') ?: [];
            sort($events, SORT_STRING);
            $head = null;
            $complete = false;
            foreach ($events as $eventPath) {
                $event = json_decode((string) @file_get_contents($eventPath), true);
                if (($event['event'] ?? null) === 'branch-created') {
                    $head = $event['data']['head'] ?? null;
                }
                if (($event['event'] ?? null) === 'complete') {
                    $complete = ($event['data']['head'] ?? null) === $head;
                }
            }
            if ($complete && self::gitOid($head)) {
                $candidate = [
                    'head' => $head,
                    'new_branch' => $intent['candidate_branch'],
                    'refresh_run_id' => basename(dirname($path)),
                ];
                self::assertCandidate($root, $intent, $candidate);
                return $candidate;
            }
        }
        if (self::gitExit($root, ['show-ref', '--verify', '--quiet', 'refs/heads/' . $intent['candidate_branch']]) === 0) {
            throw new \RuntimeException(
                'candidate branch exists without a matching completed semantic-refresh receipt'
            );
        }
        return null;
    }

    /** @param list<array<string,mixed>> $events @return ?array<string,mixed> */
    private static function sequencedEvent(
        array $events,
        string $name,
        int $sequence
    ): ?array {
        $field = str_contains($name, 'trigger') ? 'drive_sequence' : 'poll_sequence';
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) === $name
                && ($events[$index]['data'][$field] ?? null) === $sequence) {
                return $events[$index]['data'];
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $events */
    private static function nextPollSequence(array $events): int {
        $intentMax = -1;
        $resultMax = -1;
        foreach ($events as $event) {
            $sequence = $event['data']['poll_sequence'] ?? null;
            if (!is_int($sequence) || $sequence < 0) {
                continue;
            }
            if (($event['event'] ?? null) === 'origin-poll-intent') {
                $intentMax = max($intentMax, $sequence);
            }
            if (($event['event'] ?? null) === 'origin-polled') {
                $resultMax = max($resultMax, $sequence);
            }
        }
        return $intentMax > $resultMax ? $intentMax : $resultMax + 1;
    }

    /** @param list<array<string,mixed>> $events */
    private static function nextDriveSequence(array $events): int {
        $intentMax = -1;
        $resultMax = -1;
        foreach ($events as $event) {
            $sequence = $event['data']['drive_sequence'] ?? null;
            if (!is_int($sequence) || $sequence < 0) {
                continue;
            }
            if (($event['event'] ?? null) === 'origin-trigger-intent') {
                $intentMax = max($intentMax, $sequence);
            }
            if (($event['event'] ?? null) === 'origin-triggered') {
                $resultMax = max($resultMax, $sequence);
            }
        }
        if ($intentMax > $resultMax) {
            if ($intentMax > self::MAX_ORIGIN_DRIVE_SEQUENCE) {
                throw new \RuntimeException('journaled cloud-origin drive sequence exceeds its bound');
            }
            return $intentMax;
        }
        if ($resultMax >= self::MAX_ORIGIN_DRIVE_SEQUENCE) {
            throw new \RuntimeException(
                'cloud-origin export exceeded its bounded durable drive history; exact reap is required'
            );
        }
        return $resultMax + 1;
    }

    private static function recordSequencedIntent(
        PreviewRunJournal $journal,
        string $operationId,
        string $event,
        string $field,
        int $sequence
    ): void {
        if (self::sequencedEvent($journal->events($operationId), $event, $sequence) === null) {
            $journal->append($operationId, $event, [$field => $sequence]);
        }
    }

    /** @param array<string,mixed> $data */
    private static function recordOnce(
        PreviewRunJournal $journal,
        string $operationId,
        string $event,
        array $data
    ): void {
        $existing = PreviewRunJournal::eventData($journal->events($operationId), $event);
        if ($existing === null) {
            $journal->append($operationId, $event, $data);
            return;
        }
        if (EnvironmentLifecycleCanon::encode($existing) !== EnvironmentLifecycleCanon::encode($data)) {
            throw new \RuntimeException("preview recovery event '$event' changed");
        }
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private static function completionReceipt(array $event): array {
        $receipt = $event['receipt'] ?? null;
        if (!is_array($receipt) || array_is_list($receipt)
            || ($receipt['format'] ?? null) !== PortablePreviewMaterializer::RECEIPT_FORMAT
            || !is_bool($receipt['resumed'] ?? null)
            || !self::sha256($receipt['receipt_sha256'] ?? null)) {
            throw new \RuntimeException('journaled cloud preview completion receipt is malformed');
        }
        $basis = $receipt;
        $claimed = $basis['receipt_sha256'];
        unset($basis['receipt_sha256'], $basis['resumed']);
        if (!hash_equals(
            $claimed,
            hash('sha256', EnvironmentLifecycleCanon::encode($basis))
        )) {
            throw new \RuntimeException('journaled cloud preview completion receipt does not verify');
        }
        $receipt['resumed'] = true;
        return $receipt;
    }

    /** @return array{action:string,json:bool,new_branch:string,origin:string,production_ref:string,target:string,ttl_seconds:int} */
    private static function parse(array $args): array {
        $action = array_shift($args);
        $target = array_shift($args);
        if (!is_string($action) || !in_array($action, ['create', 'reap', 'sleep', 'wake'], true)
            || !is_string($target) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $target) !== 1) {
            throw new \RuntimeException(
                'use `duo preview create <cloud-env> --from=<production-env> --production-ref=<ref> --new-branch=<branch> [--ttl=<seconds>]`, or `duo preview sleep|wake|reap <cloud-env>`'
            );
        }
        $values = [];
        $json = false;
        foreach ($args as $argument) {
            if ($argument === '--format=json' && !$json) {
                $json = true;
                continue;
            }
            if (!is_string($argument) || !str_contains($argument, '=')) {
                throw new \RuntimeException('preview options must use --name=value');
            }
            [$name, $value] = explode('=', $argument, 2);
            if (!in_array($name, ['--from', '--production-ref', '--new-branch', '--ttl'], true)
                || $value === '' || array_key_exists($name, $values)) {
                throw new \RuntimeException("preview received unsupported, empty, or duplicate option '$name'");
            }
            $values[$name] = $value;
        }
        if (in_array($action, ['reap', 'sleep', 'wake'], true)) {
            if ($values !== []) {
                throw new \RuntimeException("preview $action accepts only optional --format=json");
            }
            return [
                'action' => $action, 'json' => $json, 'new_branch' => '', 'origin' => '',
                'production_ref' => '', 'target' => $target, 'ttl_seconds' => 0,
            ];
        }
        foreach (['--from', '--production-ref', '--new-branch'] as $required) {
            if (!isset($values[$required])) {
                throw new \RuntimeException("preview create requires $required=<value>");
            }
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $values['--from']) !== 1) {
            throw new \RuntimeException('preview --from must name one environment');
        }
        $ttl = self::DEFAULT_TTL;
        if (isset($values['--ttl'])) {
            if (preg_match('/^[0-9]+$/D', $values['--ttl']) !== 1) {
                throw new \RuntimeException('preview --ttl must be whole seconds');
            }
            $ttl = (int) $values['--ttl'];
        }
        if ($ttl < 60 || $ttl > 2592000) {
            throw new \RuntimeException('preview --ttl must be between 60 and 2592000 seconds');
        }
        return [
            'action' => 'create',
            'json' => $json,
            'new_branch' => $values['--new-branch'],
            'origin' => $values['--from'],
            'production_ref' => $values['--production-ref'],
            'target' => $target,
            'ttl_seconds' => $ttl,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private static function render(array $receipt, bool $json, string $action): void {
        if ($json) {
            echo json_encode(
                $receipt,
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . "\n";
            return;
        }
        if ($action === 'reap') {
            echo 'cloud preview reaped: resource=' . ($receipt['resource_id'] ?? '?')
                . ' receipt=' . ($receipt['receipt_sha256'] ?? '?') . "\n";
            return;
        }
        if (in_array($action, ['sleep', 'wake'], true)) {
            echo 'cloud preview ' . ($action === 'sleep' ? 'asleep' : 'awake')
                . ': resource=' . ($receipt['resource_id'] ?? '?')
                . ' generation=' . ($receipt['lease_generation'] ?? '?')
                . ' receipt=' . ($receipt['receipt_sha256'] ?? '?') . "\n";
            return;
        }
        echo 'cloud preview ready: ' . ($receipt['url'] ?? '?') . "\n";
        echo 'candidate=' . ($receipt['branch_ref'] ?? '?') . '@' . ($receipt['branch_commit'] ?? '?')
            . ' expires_at=' . ($receipt['expires_at'] ?? '?') . "\n";
        echo "fidelity=portable-authored-state production_fidelity=no\n";
        foreach (($receipt['fidelity']['omissions'] ?? []) as $omission) {
            echo 'omits=' . $omission . "\n";
        }
        echo 'receipt=' . ($receipt['receipt_sha256'] ?? '?') . "\n";
    }

    private static function repositoryRoot(string $startDirectory): string {
        return self::gitStdout($startDirectory, ['rev-parse', '--show-toplevel']);
    }

    private static function gitCommonDir(string $root): string {
        return self::gitStdout($root, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
    }

    /** @param list<string> $arguments */
    private static function gitStdout(string $root, array $arguments): string {
        $result = self::gitProcess($root, $arguments);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Git could not verify the cloud preview repository intent');
        }
        return trim($result['stdout']);
    }

    /** @param list<string> $arguments */
    private static function gitRedacted(
        string $root,
        array $arguments,
        string $operation
    ): string {
        $result = self::gitProcess($root, $arguments);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException("Git could not $operation; remote diagnostic is redacted");
        }
        return $result['stdout'];
    }

    /** @param list<string> $arguments */
    private static function gitExit(string $root, array $arguments): int {
        return self::gitProcess($root, $arguments)['exit'];
    }

    /** @param list<string> $arguments @return array{exit:int,stderr:string,stdout:string} */
    private static function gitProcess(string $root, array $arguments): array {
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();
        if (!is_resource($stdoutFile) || !is_resource($stderrFile)) {
            if (is_resource($stdoutFile)) fclose($stdoutFile);
            if (is_resource($stderrFile)) fclose($stderrFile);
            throw new \RuntimeException('could not create bounded Git output files');
        }
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['GIT_TERMINAL_PROMPT'] = '0';
        $process = proc_open(
            array_merge(['git', '--no-optional-locks', '-C', $root], $arguments),
            [0 => ['file', '/dev/null', 'r'], 1 => $stdoutFile, 2 => $stderrFile],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            fclose($stdoutFile);
            fclose($stderrFile);
            throw new \RuntimeException('could not start Git for cloud preview orchestration');
        }
        $deadline = microtime(true) + 55.0;
        $observedExit = null;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $observedExit = is_int($status['exitcode']) ? $status['exitcode'] : null;
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                proc_close($process);
                fclose($stdoutFile);
                fclose($stderrFile);
                throw new \RuntimeException('Git exceeded the bounded cloud preview remote deadline');
            }
            usleep(10000);
        } while (true);
        $exit = proc_close($process);
        if ($exit === -1 && $observedExit !== null && $observedExit >= 0) {
            $exit = $observedExit;
        }
        rewind($stdoutFile);
        rewind($stderrFile);
        $stdout = stream_get_contents($stdoutFile, 1048577);
        $stderr = stream_get_contents($stderrFile, 1048577);
        fclose($stdoutFile);
        fclose($stderrFile);
        if (!is_string($stdout) || !is_string($stderr)
            || strlen($stdout) > 1048576 || strlen($stderr) > 1048576) {
            throw new \RuntimeException('Git output exceeded the cloud preview evidence limit');
        }
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has unknown or missing fields");
        }
    }

    private static function gitOid(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function publicFailure(string $message): string {
        $message = trim(str_replace(["\r", "\n"], ' ', $message));
        return $message === '' ? 'preview refused without a public diagnostic' : $message;
    }
}
