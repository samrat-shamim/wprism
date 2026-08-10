<?php
declare(strict_types=1);

use Duo\Orchestrator\RefreshPlan;

require __DIR__ . '/RefreshPlan.php';

try {
    if (!in_array($argc, [4, 5, 6], true)) {
        throw new RuntimeException('usage: RefreshPlanCompile.php <worktree> <commit> <role> [complete-media|candidate <scope-contract>]');
    }
    $artifact = RefreshPlan::compileGitWorktreeWorker(
        $argv[1],
        $argv[2],
        $argv[3],
        $argv[4] ?? null,
        $argv[5] ?? null
    );
    echo json_encode(
        $artifact,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
