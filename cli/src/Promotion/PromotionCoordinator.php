<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/PromotionRecoveryDecision.php';
require_once dirname(__DIR__) . '/HostContracts/TargetInvocation.php';
require_once dirname(__DIR__) . '/PassthroughCommand.php';

/**
 * Decision-aware host promotion coordinator.
 *
 * The coordinator owns only the pre-routing contract and callback handoff;
 * target lease/checkpoint/code/apply stages remain behind the injected
 * workflow callback. This keeps the host boundary independently testable and
 * prevents a legacy three-argument callback from accidentally treating the
 * recovery witness as a frozen artifact context.
 */
final class PromotionCoordinator {
    /**
     * @param list<string> $extra
     * @param callable $scoped decision-aware scoped workflow
     * @param callable $ordinary decision-aware ordinary workflow
     * @param callable $decisionResolver
     * @param callable(bool):int $refuse value-free refusal callback
     */
    public static function run(
        TargetInvocation $driver,
        array $extra,
        callable $scoped,
        callable $ordinary,
        callable $decisionResolver,
        callable $refuse
    ): int {
        $json = in_array('--json', $extra, true) || in_array('--format=json', $extra, true);
        try {
            $decision = PromotionRecoveryDecision::fromArray(
                (array) $decisionResolver($driver, $extra)
            );
        } catch (\Throwable) {
            return $refuse($json);
        }

        foreach ($extra as $arg) {
            if (is_string($arg) && PassthroughCommand::isScopeContractFlag($arg)) {
                if (!self::accepts($scoped, 3)) {
                    return $refuse($json);
                }
                $result = $scoped($driver, $extra, $decision->toArray());
                return is_int($result) ? $result : 1;
            }
        }
        if (!self::accepts($ordinary, 4)) {
            return $refuse($json);
        }
        $result = $ordinary($driver, $extra, null, $decision->toArray());
        return is_int($result) ? $result : 1;
    }

    private static function accepts(callable $callback, int $arguments): bool {
        try {
            $reflection = is_array($callback)
                ? new \ReflectionMethod($callback[0], (string) $callback[1])
                : (is_object($callback) && !$callback instanceof \Closure
                    ? new \ReflectionMethod($callback, '__invoke')
                    : new \ReflectionFunction($callback));
            return $reflection->isVariadic() || $reflection->getNumberOfParameters() >= $arguments;
        } catch (\ReflectionException) {
            return false;
        }
    }
}
