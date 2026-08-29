<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/PassthroughCommand.php';

/**
 * Host routing boundary for public promotion.
 *
 * The ordinary/frozen and signed scoped promotion state machines remain
 * compatibility-owned by cli/wprism. This handler owns only the public selector
 * so the scope boundary can be characterized without loading target workflow
 * code or starting a transport process.
 */
final class PromoteCommand {
    /**
     * @param list<string> $extra
     * @param callable(EnvironmentDriver,list<string>):int $scoped
     * @param callable(EnvironmentDriver,list<string>,?array):array|int $ordinary
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        callable $scoped,
        callable $ordinary
    ): int {
        foreach ($extra as $arg) {
            if (is_string($arg) && PassthroughCommand::isScopeContractFlag($arg)) {
                return $scoped($driver, $extra);
            }
        }

        $result = $ordinary($driver, $extra, null);
        return is_int($result) ? $result : 1;
    }
}
