<?php
namespace WPrism;

require_once __DIR__ . '/Deploy.php';

/**
 * issue #3350 slice 8: the "LifecycleExecutor" half of the "LifecyclePlanner /
 * LifecycleExecutor" target seam -- LifecyclePlanner.php (slice 6) and
 * StateHandoffVerifier.php (slice 7) both already named this the
 * still-unextracted counterpart. Deploy::run() reconciles activation/theme
 * state against a compiled desired state; this class performs the real
 * WordPress lifecycle mutation for exactly one lifecycle-phase pass --
 * deactivate, activate (dependency-ordered), correct active_plugins storage
 * order, and switch_theme() -- and nothing else.
 *
 * Deliberately excluded from this seam and left in Deploy::run() itself: the
 * PromotionLock/Canary/state-handoff sequencing around this call, and the
 * try/catch that augments a thrown failure with Canary-observed external
 * side effects. Both are run()'s own orchestration concerns, not lifecycle
 * mutation, and moving either would risk exactly the failure-semantics
 * change this issue's guardrails forbid. Every WordPress API called here
 * fires its hooks deliberately -- see Deploy.php's own class docblock for
 * why this must never run inside Canary::arm()/disarm().
 *
 * The plugin dependency-ordering cluster (dependency_ordered_activations()/
 * dependency_ordered_deactivations() and their private helpers) stays on
 * Deploy rather than moving here too: it is a stateless, WordPress-header-
 * reading concern with no lifecycle-mutation logic of its own, and
 * sandbox/tests/offline/code-half/regress_deploy_planner.php's own source-text assertions
 * require two of those helpers (order_deactivations()/order_activations())
 * to remain private facades on Deploy.php calling DeployPlanner:: directly.
 * dependency_ordered_activations()/dependency_ordered_deactivations() widen
 * from private to public on Deploy (issue #3350 slice 8) so this class, their
 * only production caller now, can reach them as a normal cross-class call.
 */
final class LifecycleExecutor {
    /**
     * One lifecycle-phase mutation pass. $lifecyclePhase selects which of
     * retire/activate run (see Deploy::lifecycle_phase()); 'all' runs both.
     *
     * @param list<string> $toDeactivate
     * @param list<string> $toActivate
     * @param list<string> $missingPlugins plugin basenames a forced-through
     *   missing_in_code finding named; skipped rather than attempted
     * @param ?list<string> $desiredActive exact desired active_plugins
     *   order, or null when the manifest does not declare active_plugins
     * @return array{activated:list<string>, deactivated:list<string>, theme_switched:?string, order_corrected:bool, warnings:list<string>}
     */
    public static function execute(
        string $lifecyclePhase,
        array $toDeactivate,
        array $toActivate,
        array $missingPlugins,
        ?array $desiredActive,
        ?string $desiredStylesheet,
        ?string $desiredTemplate,
        bool $themeMissing,
        bool $stylesheetMismatch,
        bool $templateMismatch,
        string $promotionOwner,
        string $promotionArtifact
    ): array {
        $activated = [];
        $deactivated = [];
        $themeSwitched = null;
        $orderCorrected = false;
        $warnings = [];

        if ($lifecyclePhase !== 'activate' && $toDeactivate) {
            PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-retire');
            // WordPress's Requires Plugins header is a dependency graph,
            // not a promise that active_plugins happens to be ordered.
            // Retire dependents before their providers even when an
            // operator or old WordPress version stored a different list
            // order. Independent plugins retain the historical reverse-
            // load-order behavior.
            $deactivated = Deploy::dependency_ordered_deactivations($toDeactivate);
            deactivate_plugins($deactivated);
            $afterRetire = Deploy::current_active_plugins();
            foreach ($deactivated as $plugin) {
                if (in_array($plugin, $afterRetire, true)) {
                    throw new \RuntimeException("wprism: plugin deactivation did not persist for '$plugin'");
                }
            }
        }

        if ($lifecyclePhase !== 'retire') {
            // WordPress's Requires Plugins header is a dependency graph,
            // not a promise that active_plugins happens to be ordered.
            // Activate providers first so a clean target can satisfy the
            // native requirement check even when the desired state is the
            // alphabetical/native order produced by activate_plugin().
            // The exact authored order is restored below after all
            // membership changes have succeeded.
            $activationOrder = Deploy::dependency_ordered_activations($toActivate);
            foreach ($activationOrder as $plugin) {
                PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-activate');
                if (in_array($plugin, $missingPlugins, true)) {
                    $warnings[] = "skipped activating '$plugin' (missing_in_code, --force-code-mismatch was set)";
                    continue;
                }
                $result = activate_plugin($plugin); // hooks fire deliberately — this is the point of this class
                if (is_wp_error($result)) {
                    throw new \RuntimeException("wprism: required plugin activation failed for '$plugin'");
                }
                $activated[] = $plugin;
            }
            $after = Deploy::current_active_plugins();
            foreach ($toActivate as $plugin) {
                if (!in_array($plugin, $missingPlugins, true) && !in_array($plugin, $after, true)) {
                    throw new \RuntimeException("wprism: plugin activation did not persist for '$plugin'");
                }
            }
            if ($desiredActive !== null && !$missingPlugins && $after !== $desiredActive) {
                PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-plugin-order');
                // WordPress exposes no lifecycle API for load-order changes.
                // Membership has already been reconciled through activate/
                // deactivate above; this managed option write changes order
                // only and is verified immediately.
                update_option('active_plugins', $desiredActive);
                $after = Deploy::current_active_plugins();
                if ($after !== $desiredActive) {
                    throw new \RuntimeException('wprism: exact active plugin order did not persist');
                }
                $orderCorrected = true;
            }

            if ($desiredStylesheet !== null && ($stylesheetMismatch || $templateMismatch)) {
                PromotionLock::heartbeat($promotionOwner, $promotionArtifact, 'deploy-theme');
                if ($themeMissing) {
                    $warnings[] = "skipped theme switch to '$desiredStylesheet' (missing_in_code, --force-code-mismatch was set)";
                } else {
                    switch_theme($desiredStylesheet); // hooks fire deliberately (switch_theme/after_switch_theme)
                    $themeSwitched = $desiredStylesheet;
                    if (get_option('stylesheet') !== $desiredStylesheet) {
                        throw new \RuntimeException("wprism: theme switch did not persist for '$desiredStylesheet'");
                    }
                    if ($desiredTemplate !== null && get_option('template') !== $desiredTemplate) {
                        $actualTemplate = (string) get_option('template');
                        throw new \RuntimeException(
                            "wprism: canonical template '$desiredTemplate' cannot be realized by stylesheet "
                            . "'$desiredStylesheet': WordPress resolved '$actualTemplate'. Check this theme's Template "
                            . 'header and state/options/core.json; refusing to write template directly because that would '
                            . 'bypass WordPress theme lifecycle.'
                        );
                    }
                }
            }
        }

        return [
            'activated' => $activated,
            'deactivated' => $deactivated,
            'theme_switched' => $themeSwitched,
            'order_corrected' => $orderCorrected,
            'warnings' => $warnings,
        ];
    }
}
