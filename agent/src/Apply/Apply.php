<?php
namespace Duo;

require_once __DIR__ . '/ApplyRequestCoordinator.php';

/**
 * Stable public facade for repository planning and target application.
 *
 * Workflow state and target coordination live behind ApplyRequestCoordinator while this
 * class preserves the shipped static API used by WP-CLI and integrations.
 */
final class Apply {
    private function __construct() {}

    public static function plan(string $repo, array $opts = []): array {
        return ApplyRequestCoordinator::plan($repo, $opts);
    }

    public static function explain(string $repo, string $selector, array $opts = []): array {
        return ApplyRequestCoordinator::explain($repo, $selector, $opts);
    }

    public static function set_env_option(string $repo, string $name, string $value): array {
        return ApplyRequestCoordinator::set_env_option($repo, $name, $value);
    }

    public static function apply(string $repo, array $opts = []): array {
        return ApplyRequestCoordinator::apply($repo, $opts);
    }

    public static function verify_canonical(string $repo, array $opts = []): array {
        return ApplyRequestCoordinator::verify_canonical($repo, $opts);
    }
}
