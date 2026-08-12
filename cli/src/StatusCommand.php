<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/PlanView.php';
require_once __DIR__ . '/PlanContract.php';
require_once __DIR__ . '/PlanSummary.php';

/** Host command handler for plan-backed status rendering and readiness. */
final class StatusCommand {
    /**
     * @param callable(string,string,string):void $renderPlanRefusal
     * @param callable(array):void $renderCommandRefusal
     * @param callable(EnvironmentDriver):bool $renderAuthority
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        callable $renderPlanRefusal,
        callable $renderCommandRefusal,
        callable $renderAuthority
    ): int {
        try {
            $viewRequest = PlanView::requestFromArgs($extra);
        } catch (PlanViewException $e) {
            $renderPlanRefusal($e->reasonCode, $e->publicMessage, $e->remediation);
            $renderAuthority($driver);
            return 1;
        }
        $planArgs = ['duo', 'plan', '--repo=' . $driver->repoPath()];
        if ($viewRequest !== null) {
            $planArgs = array_merge($planArgs, PlanView::agentArgs($viewRequest));
        }
        $planArgs[] = '--format=json';
        $result = $driver->captureWp($planArgs);
        if ($result['exit'] !== 0) {
            fwrite(STDERR, "duo: status: failed to fetch plan for '{$driver->name()}' (exit {$result['exit']})\n");
            $refusal = json_decode(trim($result['stdout']), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'duo-command-refusal/v1') {
                $renderCommandRefusal($refusal);
            } else {
                $message = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
                if ($message !== '') {
                    fwrite(STDERR, $message . "\n");
                }
            }
            $renderAuthority($driver);
            return $result['exit'] !== 0 ? $result['exit'] : 1;
        }
        $plan = json_decode(trim($result['stdout']), true);
        if (!is_array($plan)) {
            fwrite(STDERR, "duo: status: could not parse plan JSON for '{$driver->name()}'\n");
            $renderAuthority($driver);
            return 1;
        }
        try {
            $plan = PlanContract::requireComplete($plan, 'status');
        } catch (\Throwable) {
            fwrite(STDERR, "duo: status: agent plan envelope is incomplete or malformed; refusing readiness\n");
            $renderAuthority($driver);
            return 1;
        }
        if ($viewRequest !== null) {
            $violations = PlanView::violations($plan, $plan['plan_view'] ?? null, $viewRequest);
            if ($violations !== []) {
                $renderPlanRefusal(
                    'plan_view_unavailable',
                    'the requested plan view is unavailable for this plan',
                    'update or repair the target agent, then rerun status with the same view filters'
                );
                $renderAuthority($driver);
                return 1;
            }
        }
        $summary = PlanSummary::render($plan, $viewRequest === null ? [] : $viewRequest['category']);
        if ($viewRequest !== null) {
            /** @var array<string,mixed> $view */
            $view = $plan['plan_view'];
            foreach (PlanView::humanHeaderLines($view) as $line) {
                echo $line . "\n";
            }
            foreach (PlanView::ordinaryHumanLines($plan, $view) as $line) {
                echo $line . "\n";
            }
        }
        foreach ($summary['lines'] as $line) {
            echo $line . "\n";
        }
        $authorityOk = $renderAuthority($driver);
        return $summary['ok'] && $authorityOk ? 0 : 1;
    }
}
