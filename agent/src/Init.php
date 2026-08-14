<?php
namespace Duo;

require_once __DIR__ . "/InitPlanner.php";
require_once __DIR__ . "/InitConfirmation.php";

/**
 * Stable public facade for first-run, target-local onboarding.
 */
final class Init {
    public const FORMAT = "duo-init-plan/v1";

    /** @return array<string,mixed> */
    public static function proposal(string $repo): array {
        return InitPlanner::proposal($repo);
    }

    /** @return array<string,mixed> */
    public static function confirm(string $repo, string $expectedDigest): array {
        return InitConfirmation::run($repo, $expectedDigest);
    }
}
