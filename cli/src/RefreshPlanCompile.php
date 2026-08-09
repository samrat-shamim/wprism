<?php
declare(strict_types=1);

use Duo\Orchestrator\RefreshPlan;

require __DIR__ . '/RefreshPlan.php';

try {
    if ($argc !== 4) {
        throw new RuntimeException('usage: RefreshPlanCompile.php <worktree> <commit> <role>');
    }
    $artifact = RefreshPlan::compileGitWorktreeWorker($argv[1], $argv[2], $argv[3]);
    echo json_encode(
        $artifact,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
