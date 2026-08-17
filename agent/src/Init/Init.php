<?php
namespace Duo;

require_once __DIR__ . '/InitPlanner.php';
require_once __DIR__ . '/InitConfirmation.php';
require_once __DIR__ . '/InitProtocol.php';

/**
 * Stable public facade for first-run, target-local onboarding.
 */
final class Init {
    public const FORMAT = InitProtocol::PLAN_FORMAT;

    /**
     * $allowUnmanagedPlugins is `wp duo init --allow-unmanaged-plugins`, and
     * the same value must be supplied to confirm(): it is inside the proposal
     * digest (InitPlanner::ALLOW_UNMANAGED_PLUGINS).
     *
     * @return array<string,mixed>
     */
    public static function proposal(string $repo, bool $allowUnmanagedPlugins = false): array {
        return InitPlanner::proposal($repo, $allowUnmanagedPlugins);
    }

    /** @return array<string,mixed> */
    public static function confirm(
        string $repo,
        string $expectedDigest,
        bool $allowUnmanagedPlugins = false
    ): array {
        return InitConfirmation::run($repo, $expectedDigest, $allowUnmanagedPlugins);
    }
}
