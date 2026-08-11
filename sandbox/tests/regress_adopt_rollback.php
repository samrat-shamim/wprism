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
require dirname(__DIR__, 2) . '/recovery/rollback-control.php';
require dirname(__DIR__, 2) . '/cli/src/Adopt.php';

use Duo\Orchestrator\AdoptionTransport;
use Duo\Orchestrator\Adopt;

final class AdoptDoubleFailureTransport implements AdoptionTransport {
    /** @var list<string> */
    public array $rawScripts = [];
    public int $wpCalls = 0;
    public int $uploads = 0;

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture adoption transport', 'remediation' => ''];
    }

    public function repoPath(): string {
        return '/fixture/repo';
    }

    public function wpPath(): string {
        return '/fixture/wordpress';
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

final class AdoptCommittedCleanupFailureTransport implements AdoptionTransport {
    /** @var list<string> */
    public array $rawScripts = [];
    public int $wpCalls = 0;

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture adoption transport', 'remediation' => ''];
    }
    public function repoPath(): string { return '/fixture/repo'; }
    public function wpPath(): string { return '/fixture/wordpress'; }
    public function uploadFile(string $localPath, string $remotePath): array {
        return is_file($localPath)
            ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
            : ['exit' => 93, 'stdout' => '', 'stderr' => 'missing fixture upload'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        return match ($this->wpCalls) {
            1 => ['exit' => 0, 'stdout' => '', 'stderr' => ''],
            2 => ['exit' => 0, 'stdout' => "/fixture/mu-plugins\n", 'stderr' => ''],
            3 => ['exit' => 0, 'stdout' => "0.5.0\n", 'stderr' => ''],
            4 => ['exit' => 0, 'stdout' => "duo-policy-ok\n", 'stderr' => ''],
            default => ['exit' => 94, 'stdout' => '', 'stderr' => 'unexpected wp fixture call'],
        };
    }
    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo duo-reachable') {
            return ['exit' => 0, 'stdout' => "duo-reachable\n", 'stderr' => ''];
        }
        if (str_contains($script, 'duo-install-complete')) {
            return ['exit' => 0, 'stdout' => "duo-repo-retained\nduo-install-complete\n", 'stderr' => ''];
        }
        if (str_contains($script, 'rollback-control.php') && str_contains($script, ' status --root=')) {
            return ['exit' => 0, 'stdout' => "{}\n", 'stderr' => ''];
        }
        if (str_contains($script, 'duo-adopt-commit-barrier')) {
            return ['exit' => 0, 'stdout' => "duo-adopt-commit-barrier\n", 'stderr' => ''];
        }
        if (str_contains($script, 'committed install retained partial backup cleanup evidence')) {
            return ['exit' => 73, 'stdout' => '', 'stderr' => 'fixture backup became undeletable'];
        }
        if (str_starts_with($script, 'rm -f ')) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        return ['exit' => 95, 'stdout' => '', 'stderr' => 'unexpected raw fixture command'];
    }
}

function adopt_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok: $message\n";
}

$installScript = new ReflectionMethod(Adopt::class, 'installScript');
$recoveryConfig = [
    'adapters' => [],
    'checkpoint_provider' => [PHP_BINARY, '/fixture/checkpoint-provider.php'],
    'exclusion_provider' => [PHP_BINARY, '/fixture/exclusion-provider.php'],
    'format' => 'duo-recovery-config/v1',
    'timeout_seconds' => 5,
];
$authorityInstall = (string) $installScript->invoke(
    null,
    '/tmp/fixture-adopt.tar',
    '/fixture/mu-plugins',
    '/fixture/repo',
    '0123456789abcdef01234567',
    'fixture-key',
    base64_encode(str_repeat('k', 32)),
    $recoveryConfig,
    null
);
$controlConfigOffset = strpos($authorityInstall, 'scoped-promotion-control.json');
$agentSwapOffset = strpos($authorityInstall, 'mv "$agent_new" "$agent"');
adopt_check(
    is_int($controlConfigOffset) && is_int($agentSwapOffset) && $controlConfigOffset < $agentSwapOffset
        && str_contains($authorityInstall, '"control_root":"/fixture/repo/.duo/control"')
        && str_contains($authorityInstall, 'source artifact contains target-local scoped promotion configuration')
        && str_contains($authorityInstall, 'chmod 600 "$agent_new/scoped-promotion-control.json"'),
    'adoption refuses source-supplied trust roots and stages its mode-0600 fixed configuration before publishing the agent'
);
$ordinaryInstall = (string) $installScript->invoke(
    null,
    '/tmp/fixture-adopt.tar',
    '/fixture/mu-plugins',
    '/fixture/repo',
    '0123456789abcdef01234567',
    null,
    null,
    null,
    null
);
adopt_check(
    !str_contains($ordinaryInstall, 'duo-scoped-promotion-control/v1')
        && !str_contains($ordinaryInstall, 'chmod 600 "$agent_new/scoped-promotion-control.json"'),
    'adoption without a verified recovery configuration exposes no scoped-promotion trust root'
);

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

$cleanupTransport = new AdoptCommittedCleanupFailureTransport();
$cleanupResult = Adopt::install($cleanupTransport, dirname(__DIR__, 2));
adopt_check($cleanupResult['exit'] === 0 && $cleanupResult['phase'] === 'complete', 'backup cleanup failure cannot reverse a committed green install');
adopt_check(
    str_contains($cleanupResult['stderr'], 'retained adoption cleanup evidence for operator recovery'),
    'committed cleanup failure returns bounded retained-evidence guidance'
);
adopt_check(
    count(array_filter($cleanupTransport->rawScripts, static fn(string $script): bool =>
        str_contains($script, 'live transaction identity changed before rollback'))) === 0,
    'no rollback is attempted after the commit barrier'
);
$barrierIndex = null;
$cleanupIndex = null;
$cleanupScript = null;
foreach ($cleanupTransport->rawScripts as $index => $script) {
    if (str_contains($script, 'duo-adopt-commit-barrier')) {
        $barrierIndex = $index;
    }
    if (str_contains($script, 'committed install retained partial backup cleanup evidence')) {
        $cleanupIndex = $index;
        $cleanupScript = $script;
    }
}
adopt_check(
    is_int($barrierIndex) && is_int($cleanupIndex) && $barrierIndex < $cleanupIndex,
    'the mutation-free commit barrier precedes destructive backup cleanup'
);
adopt_check(
    is_string($cleanupScript)
        && strpos($cleanupScript, 'rollback copy identity changed before committed cleanup')
            < strpos($cleanupScript, 'cleanup_failed=0'),
    'committed cleanup validates every rollback-root identity before deletion begins'
);

echo "REGRESS_ADOPT_ROLLBACK PASSED\n";
