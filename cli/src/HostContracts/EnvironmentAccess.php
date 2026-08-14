<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/TargetInvocation.php';

/** Host-owned environment metadata and capability boundary. */
interface EnvironmentAccess extends TargetInvocation {
    public function driverId(): string;
    public function describe(): string;
    public function capabilityReport(string $operation): DriverCapabilityReport;
}
