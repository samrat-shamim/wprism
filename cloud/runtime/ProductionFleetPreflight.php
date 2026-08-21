<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';
require_once __DIR__ . '/OperatorDiagnostics.php';
require_once __DIR__ . '/ProductionFleetConfig.php';
require_once __DIR__ . '/ProductionService.php';

/** One fleet-wide host reconciliation; worker-local checks run independently. */
final class ProductionFleetPreflight {
    public function __construct(
        private ProductionFleetConfig $config,
        private bool $requireCgroup = true,
        private ?OperatorDiagnostics $diagnostics = null
    ) {
        $this->diagnostics ??= new OperatorDiagnostics();
    }

    /** @return array<string,mixed> */
    public function refresh(): array {
        $primary = null;
        $maintenanceContended = false;
        try {
            $prepared = [];
            $hostAuthority = null;
            $hostRoot = null;
            $hostRuntime = null;
            $principals = [];
            $stateRoots = [];
            $workerRoots = [];
            foreach ($this->config->workers() as $worker) {
                $config = ProductionConfig::inspectForFleet(
                    $worker['configuration_file'],
                    $worker['configuration_sha256']
                );
                $primary ??= $config;
                $descriptor = $config->fleetWorkerDescriptor($worker['worker_id']);
                $authority = $config->hostAuthoritySha256();
                $candidateHostRoot = $config->hostPreflightRoot();
                $runtime = $config->runtime();
                $candidateHostRuntime = CanonicalJson::encode([
                    'process_launcher' => $runtime['process_launcher'],
                    'process_timeout_seconds' => $runtime['process_timeout_seconds'],
                ]);
                if ($hostAuthority === null) {
                    $hostAuthority = $authority;
                    $hostRoot = $candidateHostRoot;
                    $hostRuntime = $candidateHostRuntime;
                }
                if (!hash_equals((string) $hostAuthority, $authority)
                    || $candidateHostRoot !== $hostRoot
                    || $candidateHostRuntime !== $hostRuntime) {
                    throw new ControlRefusal('fleet worker host authorities differ');
                }
                $principal = CanonicalJson::encode($descriptor['principal']);
                $workerRoot = $descriptor['worker_root'];
                $stateRoot = $descriptor['state_root'];
                if (isset($principals[$principal])) {
                    throw new ControlRefusal('fleet worker principal is duplicated');
                }
                foreach ($workerRoots as $existing) {
                    if (self::within($workerRoot, $existing)
                        || self::within($existing, $workerRoot)) {
                        throw new ControlRefusal('fleet worker roots overlap');
                    }
                }
                foreach ($stateRoots as $existing) {
                    if (self::within($stateRoot, $existing)
                        || self::within($existing, $stateRoot)) {
                        throw new ControlRefusal('fleet worker state roots overlap');
                    }
                }
                $principals[$principal] = true;
                $stateRoots[] = $stateRoot;
                $workerRoots[] = $workerRoot;
                $admissions = [self::hostAdmission($descriptor, 'current')];
                $retiringIdentity = $worker['retiring_configuration'];
                if ($retiringIdentity !== null) {
                    $retiring = ProductionConfig::inspectForFleet(
                        $retiringIdentity['configuration_file'],
                        $retiringIdentity['configuration_sha256']
                    );
                    $retiringDescriptor = $retiring->fleetWorkerDescriptor(
                        $worker['worker_id']
                    );
                    $retiringRuntime = $retiring->runtime();
                    $retiringHostRuntime = CanonicalJson::encode([
                        'process_launcher' => $retiringRuntime['process_launcher'],
                        'process_timeout_seconds' => $retiringRuntime['process_timeout_seconds'],
                    ]);
                    if (!hash_equals($authority, $retiring->hostAuthoritySha256())
                        || $candidateHostRoot !== $retiring->hostPreflightRoot()
                        || $candidateHostRuntime !== $retiringHostRuntime
                        || CanonicalJson::encode($descriptor['principal'])
                            !== CanonicalJson::encode($retiringDescriptor['principal'])
                        || $stateRoot !== $retiringDescriptor['state_root']
                        || $workerRoot !== $retiringDescriptor['worker_root']
                        || $descriptor['preview_domain']
                            !== $retiringDescriptor['preview_domain']) {
                        throw new ControlRefusal(
                            'fleet retiring configuration changes the worker host identity'
                        );
                    }
                    $admissions[] = self::hostAdmission($retiringDescriptor, 'retiring');
                }
                $prepared[] = [$worker, $config, $descriptor, $admissions];
            }
            if (!$primary instanceof ProductionConfig || $prepared === []) {
                throw new ControlRefusal('production fleet has no worker for host preflight');
            }
            $hostWorkers = [];
            foreach ($prepared as [, , , $admissions]) {
                array_push($hostWorkers, ...$admissions);
            }
            $enteredMaintenance = false;
            try {
                $maintenance = new FileAuthorityStore(
                    $primary->hostPreflightRoot() . '/'
                        . ProductionFleetConfig::MAINTENANCE_STATE_FILE
                );
                $hostReceipt = $maintenance->locked(function (
                    AuthorityStateSession $_session
                ) use ($primary, $hostWorkers, &$enteredMaintenance): array {
                    $enteredMaintenance = true;
                    return ProductionService::refreshFleetHostPreflight(
                        $primary,
                        $hostWorkers,
                        $this->requireCgroup
                    );
                });
            } catch (\Throwable $error) {
                if (!$enteredMaintenance && $error instanceof ControlRefusal
                    && $error->getMessage() === 'authority store lock is busy') {
                    $maintenanceContended = true;
                    throw new ControlRefusal('fleet maintenance lock is busy', 0, $error);
                }
                throw $error;
            }
        } catch (\Throwable $error) {
            $this->diagnostics?->record('fleet-host-preflight', $error);
            if ($maintenanceContended || $error instanceof HostAuthorityBusy) {
                throw $error;
            }
            $primary ??= $this->invalidationConfig();
            if ($primary instanceof ProductionConfig) {
                try {
                    ProductionService::invalidateFleetHostPreflight($primary);
                } catch (\Throwable $invalidationError) {
                    $this->diagnostics?->record(
                        'fleet-host-preflight-invalidation',
                        $invalidationError
                    );
                }
            }
            throw $error;
        }
        $hostReceiptSha256 = hash(
            'sha256',
            "duo-cloud-production-host-preflight-receipt/v1\0" . CanonicalJson::encode($hostReceipt)
        );
        $results = [];
        foreach ($prepared as [$worker]) {
            $results[] = [
                'configuration_sha256' => $worker['configuration_sha256'],
                'state' => 'admitted',
                'worker_id' => $worker['worker_id'],
            ];
        }
        return [
            'format' => 'duo-cloud-production-fleet-preflight/v1',
            'host_receipt_expires_at' => $hostReceipt['expires_at'],
            'host_receipt_sha256' => $hostReceiptSha256,
            'state' => 'host_ready',
            'workers' => $results,
        ];
    }

    private function invalidationConfig(): ?ProductionConfig {
        foreach ($this->config->workers() as $worker) {
            try {
                return ProductionConfig::inspectForFleet(
                    $worker['configuration_file'],
                    $worker['configuration_sha256']
                );
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    private static function within(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    /** @param array<string,mixed> $descriptor @return array<string,mixed> */
    private static function hostAdmission(array $descriptor, string $role): array {
        return [
            'configuration_role' => $role,
            'configuration_sha256' => $descriptor['configuration_sha256'],
            'preview_domain' => $descriptor['preview_domain'],
            'reviewed_base_sha256' => $descriptor['reviewed_base_sha256'],
            'worker_id' => $descriptor['worker_id'],
            'worker_root' => $descriptor['worker_root'],
        ];
    }

}
