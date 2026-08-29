<?php
/**
 * Offline regression for LifecycleExecutor (issue #3350 slice 8: the real
 * WordPress lifecycle mutation pass -- deactivate, dependency-ordered
 * activate, active_plugins order correction, switch_theme() -- extracted
 * from Deploy::run()). Deliberately narrow, the same wiring/shape idiom the
 * earlier slices in this issue established: this file does not re-implement
 * or re-assert lifecycle mutation behavior itself -- doing so would require
 * a real WordPress install (activate_plugin()/deactivate_plugins()/
 * switch_theme() all fire real hooks), which is exactly what the sandbox's
 * live conformance sweeps already cover. This file proves the extraction
 * itself: Deploy::run() no longer inlines the moved body, only a single
 * delegating call remains, and LifecycleExecutor is a directly reachable,
 * correctly-shaped standalone collaborator.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$deploySource = file_get_contents($root . '/agent/src/Promotion/Deploy.php');
$executorSource = file_get_contents($root . '/agent/src/Promotion/LifecycleExecutor.php');
if (!is_string($deploySource) || !is_string($executorSource)) {
    fwrite(STDERR, "FAIL: could not read Deploy/LifecycleExecutor sources\n");
    exit(1);
}

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    fwrite(STDOUT, "ok: $message\n");
}

check(str_contains($deploySource, "require_once __DIR__ . '/LifecycleExecutor.php';"), 'Deploy loads the extracted lifecycle executor');
check(str_contains($executorSource, 'final class LifecycleExecutor'), 'LifecycleExecutor is a dedicated collaborator');

// === Prove the extraction itself: run() delegates to a single call, and no
// longer inlines the WP-mutation block's own logic. Canary::begin/end_
// external_observation() and the catch-block failure augmentation are
// run()'s own orchestration and must stay put, unmoved.
check(
    str_contains($deploySource, 'Canary::begin_external_observation();') && str_contains($deploySource, '$mutation = LifecycleExecutor::execute('),
    'run() still opens Canary observation itself, then delegates the mutation pass to LifecycleExecutor::execute()'
);
check(
    str_contains($deploySource, "\$externalSideEffects = Canary::end_external_observation();\n            \$detail = \$externalSideEffects"),
    "run()'s own catch-block Canary failure augmentation stays inline, unmoved"
);
check(
    !str_contains($deploySource, 'deactivate_plugins($deactivated)'),
    'Deploy.php no longer inlines the deactivation call (only LifecycleExecutor.php does)'
);
check(
    !str_contains($deploySource, 'activate_plugin($plugin); // hooks fire deliberately'),
    'Deploy.php no longer inlines the activation call (only LifecycleExecutor.php does)'
);
check(
    !str_contains($deploySource, 'switch_theme($desiredStylesheet); // hooks fire deliberately'),
    'Deploy.php no longer inlines the theme-switch call (only LifecycleExecutor.php does)'
);
check(
    str_contains($executorSource, 'deactivate_plugins($deactivated);'),
    'LifecycleExecutor.php performs the real deactivation call'
);
check(
    str_contains($executorSource, 'activate_plugin($plugin); // hooks fire deliberately'),
    'LifecycleExecutor.php performs the real activation call'
);
check(
    str_contains($executorSource, 'switch_theme($desiredStylesheet); // hooks fire deliberately'),
    'LifecycleExecutor.php performs the real theme-switch call'
);

// === The dependency-ordering cluster deliberately stays on Deploy (a
// stateless, WordPress-header-reading concern, not lifecycle mutation), with
// only its two entry points widened to public so LifecycleExecutor can call
// them normally. sandbox/tests/offline/code-half/regress_plugin_dependency_order.php and
// sandbox/tests/offline/code-half/regress_deploy_planner.php both reach into Deploy.php
// directly and are unaffected by this widening.
check(
    !preg_match('/function\s+(plugin_dependency_requirements|plugin_dependency_slug|order_deactivations|order_activations)\s*\(/', $executorSource),
    'LifecycleExecutor.php does not duplicate the dependency-ordering cluster'
);
check(
    str_contains($executorSource, 'Deploy::dependency_ordered_deactivations($toDeactivate)'),
    'LifecycleExecutor calls Deploy::dependency_ordered_deactivations() rather than re-implementing it'
);
check(
    str_contains($executorSource, 'Deploy::dependency_ordered_activations($toActivate)'),
    'LifecycleExecutor calls Deploy::dependency_ordered_activations() rather than re-implementing it'
);

// The extracted method keeps a clean parameter/return shape: every scalar
// and array run() threaded into the old inline block is now an explicit
// parameter, and every produced value comes back through one return array.
require_once $root . '/agent/src/Promotion/LifecycleExecutor.php';
require_once $root . '/agent/src/Promotion/Deploy.php';
$executor = new ReflectionClass(\WPrism\LifecycleExecutor::class);
$execute = $executor->getMethod('execute');
check($execute->isPublic(), 'execute() is public on LifecycleExecutor');
check($execute->isStatic(), 'execute() is static on LifecycleExecutor');
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $execute->getParameters()) === [
        'lifecyclePhase', 'toDeactivate', 'toActivate', 'missingPlugins', 'desiredActive',
        'desiredStylesheet', 'desiredTemplate', 'themeMissing', 'stylesheetMismatch',
        'templateMismatch', 'promotionOwner', 'promotionArtifact',
    ],
    'execute() keeps its complete, explicit twelve-parameter shape'
);

$deploy = new ReflectionClass(\WPrism\Deploy::class);
foreach (['dependency_ordered_activations', 'dependency_ordered_deactivations'] as $widened) {
    check($deploy->getMethod($widened)->isPublic(), "Deploy::$widened() widened to public (issue #3350 slice 8)");
}
foreach (['plugin_dependency_requirements', 'plugin_dependency_slug', 'order_deactivations', 'order_activations'] as $stillPrivate) {
    check($deploy->getMethod($stillPrivate)->isPrivate(), "Deploy::$stillPrivate() stays private, untouched by this slice");
}

if ($checks < 1) {
    fwrite(STDERR, "FAIL: no checks ran\n");
    exit(1);
}
printf("LifecycleExecutor regression: %d checks passed\n", $checks);
