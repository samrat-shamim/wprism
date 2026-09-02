<?php
declare(strict_types=1);

namespace WPrism;

/** Read-only primitive fencing database-backed work behind durable recovery debt. */
final class CheckpointRecoveryIntent {
    private const RELATIVE_PATH = '/.wprism/control/checkpoint-recovery-intent.json';

    public static function assert_clear(string $repo): void {
        $repoInput = rtrim($repo, '/');
        $root = realpath($repoInput);
        if ($root === false || is_link($repoInput) || !is_dir($root)) {
            throw new \RuntimeException('wprism: checkpoint recovery repository boundary is invalid');
        }
        $state = $root . '/.wprism';
        $control = $state . '/control';
        if (!file_exists($state) && !is_link($state)) {
            return;
        }
        if (!is_dir($state) || is_link($state) || realpath($state) !== $state) {
            throw new \RuntimeException('wprism: checkpoint recovery control boundary is invalid');
        }
        if (!file_exists($control) && !is_link($control)) {
            return;
        }
        if (!is_dir($control) || is_link($control) || realpath($control) !== $control) {
            throw new \RuntimeException('wprism: checkpoint recovery control boundary is invalid');
        }
        $path = $root . self::RELATIVE_PATH;
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException(
                'wprism: incomplete checkpoint recovery blocks repository observation and mutation; '
                . 'resume the exact retained-checkpoint restore through wprism recover'
            );
        }
    }
}
