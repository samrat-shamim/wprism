<?php
namespace WPrism;

require_once __DIR__ . '/InitPlanner.php';
require_once __DIR__ . '/InitConfirmation.php';
require_once __DIR__ . '/InitProtocol.php';
require_once __DIR__ . '/InitRecovery.php';

/**
 * Stable public facade for first-run, target-local onboarding.
 */
final class Init {
    public const FORMAT = InitProtocol::PLAN_FORMAT;

    /**
     * $allowUnmanagedPlugins is `wp wprism init --allow-unmanaged-plugins`, and
     * the same value must be supplied to confirm(): it is inside the proposal
     * digest (InitPlanner::ALLOW_UNMANAGED_PLUGINS).
     *
     * @return array<string,mixed>
     */
    public static function proposal(
        string $repo,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null
    ): array {
        return InitPlanner::proposal($repo, $allowUnmanagedPlugins, $lockPlan);
    }

    /**
     * $lockPlan is `wp wprism init --code-lock-b64`, and like
     * $allowUnmanagedPlugins it must be supplied identically to proposal():
     * the classification is inside the digest (InitPlanner::CODE_LOCK_ARGUMENT).
     *
     * @param ?list<array<string,mixed>> $lockPlan
     * @return array<string,mixed>
     */
    public static function confirm(
        string $repo,
        string $expectedDigest,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null
    ): array {
        return InitConfirmation::run($repo, $expectedDigest, $allowUnmanagedPlugins, $lockPlan);
    }

    /** @return array<string,mixed> */
    public static function archiveInterrupted(string $repo, string $archive): array {
        return InitRecovery::archive_interrupted_attempt($repo, $archive);
    }
}
