<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$deploySource = file_get_contents($root . '/agent/src/Promotion/Deploy.php');
$plannerSource = file_get_contents($root . '/agent/src/Promotion/DeployPlanner.php');
if (!is_string($deploySource) || !is_string($plannerSource)) {
    fwrite(STDERR, "FAIL: could not read Deploy planner sources\n");
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

check(str_contains($deploySource, "require_once __DIR__ . '/DeployPlanner.php';"), 'Deploy loads the extracted planner');
check(str_contains($plannerSource, 'final class DeployPlanner'), 'DeployPlanner is a dedicated collaborator');
check(substr_count($plannerSource, 'plugin dependency cycle prevents safe') === 2, 'both cycle refusals live in the planner');
check((bool) preg_match('/private static function order_deactivations\(array \$plugins, array \$requirements\): array \{\s*return DeployPlanner::order_deactivations/s', $deploySource), 'Deploy keeps a thin deactivation-order facade');
check((bool) preg_match('/private static function order_activations\(array \$plugins, array \$requirements\): array \{\s*return DeployPlanner::order_activations/s', $deploySource), 'Deploy keeps a thin activation-order facade');

require_once $root . '/agent/src/Promotion/DeployPlanner.php';

$requirements = [
    'dependent.php' => ['provider.php'],
    'provider.php' => [],
    'independent.php' => [],
];
check(
    Duo\DeployPlanner::order_activations(array_keys($requirements), $requirements)
        === ['provider.php', 'dependent.php', 'independent.php'],
    'activation order is provider-first with desired-list tie breaking'
);
check(
    Duo\DeployPlanner::order_deactivations(array_keys($requirements), $requirements)
        === ['independent.php', 'dependent.php', 'provider.php'],
    'deactivation order is dependent-first with reverse-list tie breaking'
);

$extraRequirements = [
    'consumer.php' => ['provider.php', 'missing.php'],
    'provider.php' => [],
];
check(
    Duo\DeployPlanner::order_activations(array_keys($extraRequirements), $extraRequirements)
        === ['provider.php', 'consumer.php'],
    'requirements outside the transition are ignored without changing order'
);

$activationCycle = false;
try {
    Duo\DeployPlanner::order_activations(['a.php', 'b.php'], ['a.php' => ['b.php'], 'b.php' => ['a.php']]);
} catch (RuntimeException $e) {
    $activationCycle = str_contains($e->getMessage(), 'safe activation: a.php, b.php');
}
check($activationCycle, 'activation cycles fail closed with deterministic plugin names');

$deactivationCycle = false;
try {
    Duo\DeployPlanner::order_deactivations(['a.php', 'b.php'], ['a.php' => ['b.php'], 'b.php' => ['a.php']]);
} catch (RuntimeException $e) {
    $deactivationCycle = str_contains($e->getMessage(), 'safe teardown: a.php, b.php');
}
check($deactivationCycle, 'deactivation cycles fail closed with deterministic plugin names');

printf("DeployPlanner regression: %d checks passed\n", $checks);
