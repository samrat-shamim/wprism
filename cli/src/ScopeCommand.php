<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/CodeDeploy.php';
require_once __DIR__ . '/Environment/AgentGateway.php';

/**
 * Host command handler for the target scope boundary.
 *
 * Scope semantics remain in the agent. The host owns only the protected
 * control-plane bootstrap, repository identity, and transport/exit boundary.
 */
final class ScopeCommand {
    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $driver, array $extra): int {
        return (new AgentGateway($driver))->streamArgs(CodeDeploy::controlArgs(array_merge(
            ['duo', 'scope', '--repo=' . $driver->repoPath()],
            $extra
        )));
    }
}
