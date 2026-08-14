<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/HostContracts/TargetInvocation.php';
require_once __DIR__ . '/EnvironmentDriver.php'; // compatibility load for direct consumers
require_once __DIR__ . '/PassthroughCommand.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/Promotion/PromotionRecoveryDecision.php';
require_once __DIR__ . '/Promotion/PromotionCoordinator.php';

/**
 * Host routing boundary for public promotion.
 *
 * The ordinary/frozen and signed scoped promotion state machines remain
 * compatibility-owned by cli/duo. This handler owns only the public selector
 * so the scope boundary can be characterized without loading target workflow
 * code or starting a transport process.
 */
final class PromoteCommand {
    private const RECOVERY_DECISION_REFUSAL = 'promotion_recovery_decision_unbound';

    /**
     * @param list<string> $extra
     * @param callable(TargetInvocation,list<string>):int $scoped
     * @param callable(TargetInvocation,list<string>,?array):array|int $ordinary
     * @param ?callable(TargetInvocation,list<string>):array $decisionResolver
     */
    public static function run(
        TargetInvocation $driver,
        array $extra,
        callable $scoped,
        callable $ordinary,
        ?callable $decisionResolver = null
    ): int {
        $json = in_array('--json', $extra, true) || in_array('--format=json', $extra, true);
        if ($decisionResolver === null) {
            return self::refuse($json);
        }
        return PromotionCoordinator::run(
            $driver,
            $extra,
            $scoped,
            $ordinary,
            $decisionResolver,
            static fn(bool $structured): int => self::refuse($structured)
        );
    }

    private static function refuse(bool $json): int {
        if ($json) {
            return CommandOutput::renderRefusalJson(
                'promote',
                self::RECOVERY_DECISION_REFUSAL,
                'promotion is unavailable until recovery provider and profile selection are durably bound and reverified before mutation',
                'use read-only plan and capability inspection until the versioned promotion-decision binding is implemented'
            );
        }
        fwrite(
            STDERR,
            "duo: promote refused: recovery provider/profile selection is not yet durably bound and reverified before mutation\n"
        );
        return 1;
    }

    /** Keep the safety source marker adjacent to the command boundary. */
    private static function hasScopeContractFlag(array $extra): bool {
        foreach ($extra as $arg) {
            if (is_string($arg) && PassthroughCommand::isScopeContractFlag($arg)) {
                return true;
            }
        }
        return false;
    }
}
