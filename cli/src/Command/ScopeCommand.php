<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';

/**
 * Host command handler for the target scope boundary.
 *
 * Scope semantics remain in the agent. The host owns only the protected
 * control-plane bootstrap, repository identity, and transport/exit boundary.
 */
final class ScopeCommand {
    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $driver, array $extra): int {
        return $driver->streamWp(CodeDeploy::controlArgs(array_merge(
            ['duo', 'scope', '--repo=' . $driver->repoPath()],
            $extra
        )));
    }
}
