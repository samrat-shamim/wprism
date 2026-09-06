<?php
declare(strict_types=1);

use WPrism\Orchestrator\RefreshPlan;

require __DIR__ . '/RefreshPlan.php';

try {
    // The worker is CLI-only, but register_argc_argv is configurable. Read the
    // tracked superglobal so a disabled setting reaches the existing usage
    // refusal instead of an undefined-variable fatal.
    $cliArgv = $_SERVER['argv'] ?? [];
    $cliArgv = is_array($cliArgv) ? array_map('strval', $cliArgv) : [];
    $cliArgc = count($cliArgv);
    if (!in_array($cliArgc, [4, 5, 6], true)) {
        throw new RuntimeException('usage: RefreshPlanCompile.php <worktree> <commit> <role> [complete-media|candidate <scope-contract>|field-diff-policy]');
    }
    if (($cliArgv[4] ?? null) === 'field-diff-policy' && $cliArgc !== 5) {
        throw new RuntimeException('field-diff-policy accepts no scope or compiler mode argument');
    }
    $artifact = ($cliArgv[4] ?? null) === 'field-diff-policy'
        ? RefreshPlan::fieldDiffPolicyWorker($cliArgv[1], $cliArgv[2], $cliArgv[3])
        : RefreshPlan::compileGitWorktreeWorker(
            $cliArgv[1],
            $cliArgv[2],
            $cliArgv[3],
            $cliArgv[4] ?? null,
            $cliArgv[5] ?? null
        );
    echo json_encode(
        $artifact,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
