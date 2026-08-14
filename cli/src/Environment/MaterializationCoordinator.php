<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/EnvironmentLifecycle.php';

/** Coordinates the existing ordered materialization phase machine. */
final class MaterializationCoordinator
{
    /**
     * @param callable(EnvironmentDriver,array<string,mixed>):array<string,mixed>|int $promote
     * @return array<string,mixed>
     */
    public static function run(
        EnvironmentDriver $sourceDriver,
        EnvironmentDriver $targetDriver,
        CommandEnvironmentProvider $sourceProvider,
        CommandEnvironmentProvider $targetProvider,
        EnvironmentLifecycleJournal $journal,
        MaterializationRequest $request,
        callable $promote
    ): array {
        return EnvironmentMaterializer::materialize(
            $sourceDriver,
            $targetDriver,
            $sourceProvider,
            $targetProvider,
            $journal,
            $request->lifecycleOptions(),
            $promote
        );
    }
}
