<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/ProviderSettlementIntent.php';

/** Read-only primitive fencing database-backed work behind durable recovery debt. */
final class CheckpointRecoveryIntent {
    private const RELATIVE_PATH = '/.wprism/control/checkpoint-recovery-intent.json';

    public static function assert_clear(string $repo): void {
        $root = self::repoRoot($repo);
        $path = $root . self::RELATIVE_PATH;
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException(
                'wprism: incomplete checkpoint recovery blocks repository observation and mutation; '
                . 'resume the exact retained-checkpoint restore through wprism recover'
            );
        }
    }

    /**
     * Authenticate the exact retained checkpoint before recreating its latest
     * ordinary promotion lease. An already-published checkpoint recovery is a
     * resume path and may not elect another database lease; before publication
     * the only permissible external debt is the matching provider transaction.
     */
    public static function assert_initial_recovery(
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash
    ): void {
        $root = self::repoRoot($repo);
        $path = $root . self::RELATIVE_PATH;
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException(
                'wprism: active checkpoint recovery must resume without electing another promotion lease'
            );
        }
        ProviderSettlementIntent::assert_recovery_or_clear(
            $root,
            $checkpoint,
            $cipherSha256,
            $owner,
            $artifactHash
        );
    }

    private static function repoRoot(string $repo): string {
        $repoInput = rtrim($repo, '/');
        $root = realpath($repoInput);
        if ($root === false || is_link($repoInput) || !is_dir($root)) {
            throw new \RuntimeException('wprism: checkpoint recovery repository boundary is invalid');
        }
        $state = $root . '/.wprism';
        $control = $state . '/control';
        foreach ([$state, $control] as $directory) {
            if (!file_exists($directory) && !is_link($directory)) {
                continue;
            }
            if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
                throw new \RuntimeException('wprism: checkpoint recovery control boundary is invalid');
            }
        }
        return $root;
    }
}
