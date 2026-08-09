<?php
declare(strict_types=1);

/**
 * Offline regression for the adoption double-failure boundary.
 *
 * The deterministic transport drives the complete product transaction through
 * archive creation, upload, and remote swap, then throws during the first
 * post-swap verification while the exact rollback command also fails. Before
 * DUO-3309, Adopt's finally block discarded that rollback result and surfaced
 * only the verification exception.
 */

require dirname(__DIR__, 2) . '/cli/src/Transport.php';
require dirname(__DIR__, 2) . '/cli/src/Adopt.php';

use Duo\Orchestrator\AdoptionTransport;
use Duo\Orchestrator\Adopt;

final class AdoptDoubleFailureTransport implements AdoptionTransport {
    /** @var list<string> */
    public array $rawScripts = [];
    public int $wpCalls = 0;
    public int $uploads = 0;

    public function repoPath(): string {
        return '/fixture/repo';
    }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo duo-reachable') {
            return ['exit' => 0, 'stdout' => "duo-reachable\n", 'stderr' => ''];
        }
        if (str_contains($script, 'duo-install-complete')) {
            return ['exit' => 0, 'stdout' => "duo-repo-retained\nduo-install-complete\n", 'stderr' => ''];
        }
        if (str_contains($script, 'txn=') && str_contains($script, '.duo-adopt-txn-')) {
            return ['exit' => 23, 'stdout' => '', 'stderr' => 'restore mv failed'];
        }
        if (str_starts_with($script, 'rm -f ')) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        return ['exit' => 91, 'stdout' => '', 'stderr' => 'unexpected raw fixture command'];
    }

    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        if ($this->wpCalls === 1 && $wpArgs === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($this->wpCalls === 2 && $wpArgs === ['eval', 'echo WPMU_PLUGIN_DIR;']) {
            return ['exit' => 0, 'stdout' => "/fixture/mu-plugins\n", 'stderr' => ''];
        }
        if ($this->wpCalls === 3) {
            throw new \RuntimeException('post-swap verification exploded');
        }
        return ['exit' => 92, 'stdout' => '', 'stderr' => 'unexpected wp fixture command'];
    }

    public function uploadFile(string $localPath, string $remotePath): array {
        $this->uploads++;
        if (!is_file($localPath) || !str_starts_with($remotePath, '/tmp/duo-adopt-')) {
            return ['exit' => 93, 'stdout' => '', 'stderr' => 'invalid upload fixture arguments'];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
}

function adopt_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok: $message\n";
}

$transport = new AdoptDoubleFailureTransport();
$caught = null;
try {
    Adopt::install($transport, dirname(__DIR__, 2));
} catch (\Throwable $error) {
    $caught = $error;
}

adopt_check($caught instanceof \RuntimeException, 'the post-swap double failure is surfaced');
adopt_check(
    str_contains($caught->getMessage(), 'post-swap verification exploded'),
    'the original post-swap verification failure remains visible'
);
adopt_check(
    str_contains($caught->getMessage(), 'adoption rollback could not be confirmed: restore mv failed'),
    'the failed restore is appended to the surfaced error'
);
adopt_check(
    $caught->getPrevious() instanceof \RuntimeException
        && $caught->getPrevious()->getMessage() === 'post-swap verification exploded',
    'the original exception remains chained for programmatic diagnostics'
);
adopt_check($transport->uploads === 1, 'the regression reaches the product archive-upload boundary');
adopt_check(
    count(array_filter($transport->rawScripts, static fn(string $script): bool =>
        !str_contains($script, 'duo-install-complete')
            && str_contains($script, 'txn=')
            && str_contains($script, '.duo-adopt-txn-'))) === 1,
    'the exception path attempts the exact adoption rollback once'
);
adopt_check(
    count(array_filter($transport->rawScripts, static fn(string $script): bool =>
        str_starts_with($script, 'rm -f '))) === 1,
    'remote archive cleanup still runs before the combined failure surfaces'
);

echo "REGRESS_ADOPT_ROLLBACK PASSED\n";
