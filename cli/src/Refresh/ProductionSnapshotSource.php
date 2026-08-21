<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Verified immutable production truth supplied without a live target shell.
 *
 * A cloud origin export is not an EnvironmentDriver command surface. It is a
 * signed, content-addressed observation bound to one exact production Git
 * commit. Refresh still performs both of its observation reads; an immutable
 * implementation may return the same verified bytes, while a live
 * implementation may reacquire and therefore detect a moving snapshot.
 */
interface ProductionSnapshotSource {
    /** Refuse unless this source is bound to the exact production commit. */
    public function assertProductionRevision(string $expectedCommit): void;

    /**
     * Return one verified `duo-refresh-production/v1` document.
     *
     * @param ?array<string,mixed> $scopeContract
     * @return array<string,mixed>
     */
    public function readProductionSnapshot(string $expectedCommit, ?array $scopeContract = null): array;
}
