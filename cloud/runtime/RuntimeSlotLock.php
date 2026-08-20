<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** One fail-fast lock shared by lifecycle mutations and signed commands. */
final class RuntimeSlotLock {
    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function exclusive(string $slot, callable $callback): mixed {
        self::privateDirectory($slot);
        $path = $slot . '/runtime.lock';
        self::privateFileOrAbsent($path);
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle) || !@chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('runtime slot lock could not be opened privately');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $named = @lstat($path);
            if (!is_array($opened) || !is_array($named)
                || !self::sameFile($opened, $named)
                || (($named['mode'] ?? 0) & 0170000) !== 0100000
                || (($named['mode'] ?? 0) & 0777) !== 0600
                || (int) ($named['nlink'] ?? 0) !== 1
                || !self::processOwned($named)) {
                throw new ControlRefusal('runtime slot lock pathname is unsafe');
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new ControlRefusal('runtime slot lock is busy');
            }
            clearstatcache(true, $path);
            $held = @lstat($path);
            if (!is_array($held) || !self::sameFile($opened, $held)
                || (($held['mode'] ?? 0) & 0777) !== 0600
                || (int) ($held['nlink'] ?? 0) !== 1
                || !self::processOwned($held)) {
                throw new ControlRefusal('runtime slot lock pathname changed while held');
            }
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function privateDirectory(string $path): void {
        $real = realpath($path);
        $stat = @lstat($path);
        if (!is_string($real) || $real !== rtrim($path, '/')
            || !is_array($stat) || is_link($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0040000
            || (($stat['mode'] ?? 0) & 0777) !== 0700
            || !self::processOwned($stat)) {
            throw new ControlRefusal('runtime slot lock directory is not private and canonical');
        }
    }

    private static function privateFileOrAbsent(string $path): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (is_link($path) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($stat['mode'] ?? 0) & 0777) !== 0600
            || (int) ($stat['nlink'] ?? 0) !== 1
            || !self::processOwned($stat)) {
            throw new ControlRefusal('runtime slot lock path is unsafe');
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (($left['mode'] ?? 0) & 0170000) === 0100000
            && (($right['mode'] ?? 0) & 0170000) === 0100000
            && (string) ($left['dev'] ?? '') === (string) ($right['dev'] ?? '')
            && (string) ($left['ino'] ?? '') === (string) ($right['ino'] ?? '');
    }

    /** @param array<string|int,mixed> $stat */
    private static function processOwned(array $stat): bool {
        return !function_exists('posix_geteuid')
            || (int) ($stat['uid'] ?? -1) === posix_geteuid();
    }
}
