<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/Refresh.php';

/**
 * Application-facing refresh boundary. Parsing and presentation remain in
 * RefreshCommand/RebaseCommand; this facade is the seam where observation,
 * planning, and candidate publication can be injected independently.
 */
final class RefreshWorkflow
{
    /** @return array<string,mixed> */
    public static function observeAndPlan(
        EnvironmentDriver $transport,
        string $productionRef,
        ?array $scopeContract = null,
        bool $includeFieldDiff = false
    ): array {
        return Refresh::refresh($transport, $productionRef, $scopeContract, $includeFieldDiff);
    }

    /** @return array{plan_path:string,run_id:string,new_branch:string,head:string} */
    public static function publishCandidate(
        EnvironmentDriver $transport,
        string $productionRef,
        string $newBranch,
        array $resolution = [],
        ?array $scopeContract = null,
        ?string $fieldResolutionPath = null,
        bool $interactive = false,
        bool $legacyStrategyExplicit = false
    ): array {
        return Refresh::rebase(
            $transport,
            $productionRef,
            $newBranch,
            $resolution,
            $scopeContract,
            $fieldResolutionPath,
            $interactive,
            $legacyStrategyExplicit
        );
    }

    public static function abort(string $runId): void
    {
        Refresh::abort($runId);
    }
}
