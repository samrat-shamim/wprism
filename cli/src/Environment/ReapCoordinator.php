<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/EnvironmentLifecycle.php';

/** Coordinates the idempotent, lease-fenced reap phase machine. */
final class ReapCoordinator
{
    /** @return array<string,mixed> */
    public static function run(
        EnvironmentDriver $targetDriver,
        CommandEnvironmentProvider $targetProvider,
        EnvironmentLifecycleJournal $journal,
        ?CommandEnvironmentProvider $sourceProvider
    ): array {
        return EnvironmentMaterializer::reap(
            $targetDriver,
            $targetProvider,
            $journal,
            $sourceProvider
        );
    }
}
