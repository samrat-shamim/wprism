<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';
require_once __DIR__ . '/OperatorDiagnostics.php';
require_once __DIR__ . '/ProductionConfig.php';
require_once __DIR__ . '/ProductionDeploymentProofs.php';
require_once __DIR__ . '/ProductionFleetConfig.php';
require_once __DIR__ . '/ProductionService.php';

/** One host-wide, non-overlapping pass over the fleet's single-site workers. */
final class ProductionFleetReaper {
    /**
     * @var \Closure(array{
     *     configuration_file:string,
     *     configuration_sha256:string,
     *     retiring_configuration:null,
     *     worker_id:string
     * }):array<string,mixed>
     */
    private \Closure $workerSweep;

    /**
     * @var \Closure(string,list<string>,list<string>,int,int):void
     */
    private \Closure $namespaceReconciler;

    /**
     * @param callable(array{
     *     configuration_file:string,
     *     configuration_sha256:string,
     *     retiring_configuration:null,
     *     worker_id:string
     * }):array<string,mixed>|null $workerSweep
     * @param callable(string,list<string>,list<string>,int,int):void|null $namespaceReconciler
     */
    public function __construct(
        private ProductionFleetConfig $config,
        ?callable $workerSweep = null,
        private ?OperatorDiagnostics $diagnostics = null,
        bool $requireCgroup = true,
        ?ContainerArgvProcessRunner $processRunner = null,
        ?callable $namespaceReconciler = null
    ) {
        $this->workerSweep = $workerSweep === null
            ? static fn (array $worker): array => ProductionService::janitorFromConfigFile(
                $worker['configuration_file'],
                $worker['configuration_sha256'],
                $requireCgroup,
                $processRunner
            )->sweep(1)
            : \Closure::fromCallable($workerSweep);
        $this->namespaceReconciler = $namespaceReconciler === null
            ? static function (
                string $hostRoot,
                array $configurationSha256s,
                array $runtimeReviewSha256s,
                int $ownerUid,
                int $ownerGid
            ): void {
                ProductionDeploymentProofs::reconcileFleetNamespace(
                    $hostRoot,
                    $configurationSha256s,
                    $runtimeReviewSha256s,
                    $ownerUid,
                    $ownerGid
                );
            }
            : \Closure::fromCallable($namespaceReconciler);
        $this->diagnostics ??= new OperatorDiagnostics();
    }

    /** @return array<string,mixed> */
    public function sweep(): array {
        [
            $lock,
            $hostRoot,
            $configurationSha256s,
            $runtimeReviewSha256s,
            $ownerUid,
            $ownerGid,
        ] = $this->fleetLock();
        return $lock->locked(
            fn (AuthorityStateSession $_session): array => $this->lockedSweep(
                $hostRoot,
                $configurationSha256s,
                $runtimeReviewSha256s,
                $ownerUid,
                $ownerGid
            )
        );
    }

    /**
     * @param list<string> $configurationSha256s
     * @param list<string> $runtimeReviewSha256s
     * @return array<string,mixed>
     */
    private function lockedSweep(
        string $hostRoot,
        array $configurationSha256s,
        array $runtimeReviewSha256s,
        int $ownerUid,
        int $ownerGid
    ): array {
        ($this->namespaceReconciler)(
            $hostRoot,
            $configurationSha256s,
            $runtimeReviewSha256s,
            $ownerUid,
            $ownerGid
        );
        $examined = 0;
        $reaped = 0;
        $refused = 0;
        $workerRefusals = 0;
        $workers = [];
        foreach ($this->config->workers() as $worker) {
            $workerState = 'complete';
            try {
                [$result, $configurationSha256, $attemptRefusal] = $this->sweepWorker(
                    $worker
                );
                $workerExamined = $result['examined'];
                $workerReaped = $result['reaped'];
                $workerRefused = $result['refused'];
                if ($attemptRefusal) {
                    $this->diagnostics?->record(
                        'fleet-expired-preview-reap',
                        new ControlRefusal('fleet retiring configuration could not be used')
                    );
                    $workerRefusals++;
                }
                if ($workerRefused !== 0 || $attemptRefusal) {
                    $workerState = 'refused';
                }
            } catch (\Throwable $error) {
                $this->diagnostics?->record('fleet-expired-preview-reap', $error);
                $configurationSha256 = $worker['configuration_sha256'];
                $workerExamined = 0;
                $workerReaped = 0;
                $workerRefused = 0;
                $workerRefusals++;
                $workerState = 'refused';
            }
            $examined += $workerExamined;
            $reaped += $workerReaped;
            $refused += $workerRefused;
            $workers[] = [
                'configuration_sha256' => $configurationSha256,
                'examined' => $workerExamined,
                'reaped' => $workerReaped,
                'refused' => $workerRefused,
                'state' => $workerState,
                'worker_id' => $worker['worker_id'],
            ];
        }
        return [
            'examined' => $examined,
            'format' => 'duo-cloud-expired-preview-fleet-sweep/v1',
            'reaped' => $reaped,
            'refused' => $refused,
            'state' => $refused === 0 && $workerRefusals === 0 ? 'complete' : 'degraded',
            'worker_refusals' => $workerRefusals,
            'workers' => $workers,
        ];
    }

    /**
     * @param array{
     *     configuration_file:string,
     *     configuration_sha256:string,
     *     retiring_configuration:array{configuration_file:string,configuration_sha256:string}|null,
     *     worker_id:string
     * } $worker
     * @return array{0:array{examined:int,reaped:int,refused:int},1:string,2:bool}
     */
    private function sweepWorker(array $worker): array {
        $retiringResult = null;
        $attemptRefusal = false;
        if ($worker['retiring_configuration'] !== null) {
            try {
                $retiringResult = ($this->workerSweep)([
                    'configuration_file' => $worker['retiring_configuration']['configuration_file'],
                    'configuration_sha256' => $worker['retiring_configuration']['configuration_sha256'],
                    'retiring_configuration' => null,
                    'worker_id' => $worker['worker_id'],
                ]);
                self::assertResult($retiringResult);
            } catch (\Throwable) {
                $attemptRefusal = true;
                $retiringResult = null;
            }
            if ($retiringResult !== null && $retiringResult['reaped'] === 1) {
                return [
                    $retiringResult,
                    $worker['retiring_configuration']['configuration_sha256'],
                    $attemptRefusal,
                ];
            }
        }

        try {
            $currentResult = ($this->workerSweep)([
                'configuration_file' => $worker['configuration_file'],
                'configuration_sha256' => $worker['configuration_sha256'],
                'retiring_configuration' => null,
                'worker_id' => $worker['worker_id'],
            ]);
            self::assertResult($currentResult);
        } catch (\Throwable $error) {
            if ($retiringResult !== null) {
                return [
                    $retiringResult,
                    $worker['retiring_configuration']['configuration_sha256'],
                    true,
                ];
            }
            throw $error;
        }
        if ($currentResult['reaped'] === 1
            || $currentResult['refused'] === 1
            || $retiringResult === null
            || $retiringResult['refused'] === 0) {
            return [$currentResult, $worker['configuration_sha256'], $attemptRefusal];
        }
        return [
            $retiringResult,
            $worker['retiring_configuration']['configuration_sha256'],
            $attemptRefusal,
        ];
    }

    /**
     * @return array{0:FileAuthorityStore,1:string,2:list<string>,3:list<string>,4:int,5:int}
     */
    private function fleetLock(): array {
        $hostRoot = null;
        $hostAuthority = null;
        $principals = [];
        $stateRoots = [];
        $workerRoots = [];
        $configurationSha256s = [];
        $runtimeReviewSha256s = [];
        foreach ($this->config->workers() as $worker) {
            $current = ProductionConfig::inspectForFleet(
                $worker['configuration_file'],
                $worker['configuration_sha256']
            );
            $current->assertHostPreflightRuntime();
            $descriptor = $current->fleetWorkerDescriptor($worker['worker_id']);
            $candidateRoot = $current->hostPreflightRoot();
            $candidateAuthority = $current->hostAuthoritySha256();
            if ($hostRoot !== null && $candidateRoot !== $hostRoot) {
                throw new ControlRefusal('fleet reaper host preflight roots differ');
            }
            if ($hostAuthority !== null
                && !hash_equals($hostAuthority, $candidateAuthority)) {
                throw new ControlRefusal('fleet reaper host authorities differ');
            }
            $hostRoot ??= $candidateRoot;
            $hostAuthority ??= $candidateAuthority;
            $principal = CanonicalJson::encode($descriptor['principal']);
            $stateRoot = $descriptor['state_root'];
            $workerRoot = $descriptor['worker_root'];
            if ($descriptor['worker_id'] !== $worker['worker_id']
                || isset($principals[$principal])) {
                throw new ControlRefusal(
                    'fleet reaper worker principal is duplicated or worker id is unbound'
                );
            }
            foreach ($workerRoots as $existing) {
                if (self::within($workerRoot, $existing)
                    || self::within($existing, $workerRoot)) {
                    throw new ControlRefusal('fleet reaper worker roots overlap');
                }
            }
            foreach ($stateRoots as $existing) {
                if (self::within($stateRoot, $existing)
                    || self::within($existing, $stateRoot)) {
                    throw new ControlRefusal('fleet reaper state roots overlap');
                }
            }
            $principals[$principal] = true;
            $stateRoots[] = $stateRoot;
            $workerRoots[] = $workerRoot;
            $configurationSha256s[$current->sha256()] = true;
            $runtimeReviewSha256s[(string) $current->runtime()['review_receipt_sha256']] = true;
            if ($worker['retiring_configuration'] === null) {
                continue;
            }
            // Cleanup authority is the complete current+retiring fleet. An
            // unreadable retiring pin makes that keep set unknowable, so no
            // proof deletion or worker effect may begin from a partial view.
            $retiring = ProductionConfig::inspectForFleet(
                $worker['retiring_configuration']['configuration_file'],
                $worker['retiring_configuration']['configuration_sha256']
            );
            $retiring->assertHostPreflightRuntime();
            if ($retiring->hostPreflightRoot() !== $candidateRoot
                || $retiring->hostAuthoritySha256() !== $candidateAuthority
                || $retiring->stateRoot() !== $current->stateRoot()
                || $retiring->workerRoot() !== $current->workerRoot()
                || CanonicalJson::encode($retiring->principal())
                    !== CanonicalJson::encode($current->principal())
                || ($retiring->runtime()['preview_domain'] ?? null)
                    !== ($current->runtime()['preview_domain'] ?? null)) {
                throw new ControlRefusal(
                    'fleet retiring configuration does not identify the current worker'
                );
            }
            $configurationSha256s[$retiring->sha256()] = true;
            $runtimeReviewSha256s[(string) $retiring->runtime()['review_receipt_sha256']] = true;
        }
        if ($hostRoot === null) {
            throw new ControlRefusal('production fleet has no worker for expired preview reap');
        }
        $hostStat = @lstat($hostRoot);
        if (!is_array($hostStat)
            || !is_int($hostStat['uid'] ?? null)
            || !is_int($hostStat['gid'] ?? null)) {
            throw new ControlRefusal('fleet reaper host proof owner identity is unavailable');
        }
        $configurationKeep = array_keys($configurationSha256s);
        $runtimeKeep = array_keys($runtimeReviewSha256s);
        sort($configurationKeep, SORT_STRING);
        sort($runtimeKeep, SORT_STRING);
        return [
            new FileAuthorityStore(
                $hostRoot . '/' . ProductionFleetConfig::MAINTENANCE_STATE_FILE
            ),
            $hostRoot,
            $configurationKeep,
            $runtimeKeep,
            $hostStat['uid'],
            $hostStat['gid'],
        ];
    }

    private static function within(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    /** @param array<string,mixed> $result */
    private static function assertResult(array $result): void {
        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== ['examined', 'reaped', 'refused']
            || !is_int($result['examined']) || $result['examined'] < 0 || $result['examined'] > 1
            || !is_int($result['reaped']) || $result['reaped'] < 0 || $result['reaped'] > 1
            || !is_int($result['refused']) || $result['refused'] < 0 || $result['refused'] > 1
            || $result['reaped'] + $result['refused'] !== $result['examined']) {
            throw new ControlRefusal('fleet expired preview worker result is invalid');
        }
    }
}
