<?php
namespace Duo;

require_once __DIR__ . '/Canon.php';

// Publish declares this on the historical load path; retain a guarded
// declaration so the standalone filesystem service is independently usable.
if (!class_exists(InitialStateBoundaryException::class, false)) {
    final class InitialStateBoundaryException extends \RuntimeException {}
}

/**
 * Filesystem-only durability and ownership boundary.
 *
 * No publication phases, database callbacks, WordPress lifecycle policy, or
 * lease state belongs here.  The service owns only inode/type/byte witnesses,
 * recursive manifests, hard fsync boundaries, and exact owned-object
 * compensation.
 */
final class DurableFilesystem {
    /** @return array{type:string,dev:string,ino:string,sha256?:string} */
    public static function identity(string $path, string $type): array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new InitialStateBoundaryException('duo: initial publication path identity is unavailable');
        }
        $identity = [
            'type' => $type,
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
        ];
        if ($type === 'file') {
            if (is_link($path) || !is_file($path)) {
                throw new InitialStateBoundaryException('duo: initial publication regular file changed type');
            }
            $digest = @hash_file('sha256', $path);
            if (!is_string($digest)) {
                throw new InitialStateBoundaryException('duo: initial publication regular file could not be hashed');
            }
            $identity['sha256'] = $digest;
        } elseif ($type === 'link') {
            if (!is_link($path)) {
                throw new InitialStateBoundaryException('duo: initial publication link changed type');
            }
        }
        return $identity;
    }

    /** Reject a lexical root that is a symlink, including a broken link. */
    public static function assertRoot(string $path, string $label): void {
        $probe = rtrim($path, '/\\');
        if ($probe === '') {
            $probe = $path;
        }
        clearstatcache(true, $probe);
        $stat = @lstat($probe);
        $mode = is_array($stat) ? (int) ($stat['mode'] ?? 0) : 0;
        if (is_array($stat) && ($mode & 0170000) === 0120000) {
            throw new \RuntimeException("duo: refusing to operate on symlinked $label root $path");
        }
    }

    /** Deterministic digest of every regular file beneath a tree. */
    public static function treeDigest(string $directory): string {
        self::assertRoot($directory, 'capture tree');
        if (!is_dir($directory)) {
            throw new \RuntimeException("duo: cannot hash missing tree $directory");
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException(
                    "duo: refusing to hash symlink in capture tree: {$file->getPathname()}"
                );
            }
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1);
            }
        }
        sort($files, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($files as $relative) {
            $bytes = Canon::read_file($directory . '/' . $relative);
            hash_update($ctx, $relative . "\0" . $bytes . "\0");
        }
        return hash_final($ctx);
    }

    /** @return array{root:array<string,string>,entries:list<array<string,mixed>>} */
    public static function ownershipManifest(string $directory): array {
        $root = self::identity($directory, 'directory');
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $prefix = strlen(rtrim($directory, '/')) + 1;
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = substr($path, $prefix);
            if ($item->isLink()) {
                $row = self::identity($path, 'link');
                $row['target'] = (string) readlink($path);
            } elseif ($item->isDir()) {
                $row = self::identity($path, 'directory');
            } elseif ($item->isFile()) {
                $row = self::identity($path, 'file');
            } else {
                $row = self::identity($path, 'special');
            }
            $row['path'] = $relative;
            $entries[] = $row;
        }
        usort($entries, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return ['root' => $root, 'entries' => $entries];
    }

    /** @param array{root:array<string,string>,entries:list<array<string,mixed>>} $manifest */
    public static function assertOwned(string $path, array $manifest, string $label): void {
        if (Canon::encode(self::ownershipManifest($path)) !== Canon::encode($manifest)) {
            throw new InitialStateBoundaryException("duo: $label changed after Duo created it; preserving it");
        }
    }

    /** @param array{root:array<string,string>,entries:list<array<string,mixed>>} $manifest */
    public static function removeOwned(string $path, array $manifest, string $label): void {
        self::assertOwned($path, $manifest, $label);
        $claim = dirname($path) . '/.' . basename($path) . '.duo-claim-' . bin2hex(random_bytes(8));
        if (!@rename($path, $claim)) {
            throw new InitialStateBoundaryException("duo: could not claim $label for compensation");
        }
        try {
            self::assertOwned($claim, $manifest, $label);
        } catch (\Throwable $failure) {
            if (!file_exists($path) && !is_link($path)) {
                @rename($claim, $path);
            }
            throw $failure;
        }
        self::removeTree($claim);
    }

    /** @return array{type:string,dev:string,ino:string} */
    public static function directoryIdentity(string $path): array {
        return self::identity($path, 'directory');
    }

    /** @return array{type:string,dev:string,ino:string,sha256:string} */
    public static function fileIdentity(string $path): array {
        return self::identity($path, 'file');
    }

    public static function syncParent(string $path): void {
        self::syncDirectory(dirname($path));
    }

    public static function syncFile(string $path): void {
        self::syncHandle($path, 0100000, 'file');
    }

    public static function syncDirectory(string $path): void {
        self::syncHandle($path, 0040000, 'directory');
    }

    private static function syncHandle(string $path, int $expectedType, string $label): void {
        if (!function_exists('fsync')) {
            throw new \RuntimeException("duo: durable $label sync is unavailable on this platform");
        }
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (!is_array($named)
            || is_link($path)
            || (((int) ($named['mode'] ?? 0)) & 0170000) !== $expectedType) {
            throw new \RuntimeException("duo: durable $label sync target is missing or changed type");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("duo: durable $label sync target could not be opened");
        }
        try {
            $opened = @fstat($handle);
            if (!is_array($opened)
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || @fsync($handle) !== true) {
                throw new \RuntimeException("duo: durable $label sync did not complete on the witnessed inode");
            }
        } finally {
            fclose($handle);
        }
    }

    public static function removeTree(string $directory): void {
        self::assertRoot($directory, 'directory');
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }

}
