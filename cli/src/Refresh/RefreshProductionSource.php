<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/ProductionSnapshotSource.php';

/**
 * Non-shell production source accepted by Refresh's semantic planning path.
 *
 * The environment name is plan/journal identity only. This interface does not
 * inherit EnvironmentDriver and therefore cannot accidentally acquire WP,
 * shell, database, deployment, or provider-command authority.
 */
interface RefreshProductionSource extends ProductionSnapshotSource {
    public function productionEnvironmentName(): string;
}
