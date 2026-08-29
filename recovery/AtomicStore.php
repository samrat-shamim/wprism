<?php
declare(strict_types=1);

namespace WPrism\Recovery;

/**
 * Durable, exact-byte filesystem publication for recovery protocol records.
 *
 * Resource bundles retain their domain-specific labels and validation, while
 * this class owns the shared write ordering: create-only temp, fsync file,
 * rename, chmod, fsync parent, and readback.
 */
final class AtomicStore {
    /** @return array<string,mixed> */
    public static function readCanonical(string $path, string $label, string $scope): array {
        return CanonicalJson::read($path, $label, $scope);
    }

    public static function publishExact(
        string $path,
        string $bytes,
        int $mode,
        string $label,
        string $scope,
        bool $createParent = true,
        ?callable $checkpoint = null
    ): void {
        self::assertRegularOrAbsent($path, "$label path", $scope);
        if (is_file($path)) {
            $existing = @file_get_contents($path);
            if (!is_string($existing) || !hash_equals(hash('sha256', $bytes), hash('sha256', $existing))) {
                throw new \RuntimeException("$scope: immutable $label already differs");
            }
            return;
        }
        self::atomicWrite($path, $bytes, $mode, $label, $scope, $createParent, $checkpoint);
    }

    public static function atomicWrite(
        string $path,
        string $bytes,
        int $mode,
        string $label,
        string $scope,
        bool $createParent = false,
        ?callable $checkpoint = null
    ): void {
        $dir = dirname($path);
        if ($createParent) {
            self::ensureDirectory($dir, 0700, $scope);
        } elseif (!is_dir($dir) || is_link($dir)) {
            throw new \RuntimeException("$scope: unsafe $label directory");
        }
        self::assertRegularOrAbsent($path, $label, $scope);
        $tmp = $dir . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        $handle = null;
        $published = false;
        try {
            self::checkpoint($checkpoint, 'before-write');
            $handle = @fopen($tmp, 'x+b');
            if (!is_resource($handle)) {
                throw new \RuntimeException("$scope: could not create temporary $label");
            }
            @chmod($tmp, $mode);
            $written = fwrite($handle, $bytes);
            if ($written !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException("$scope: could not durably write temporary $label");
            }
            self::checkpoint($checkpoint, 'after-file-fsync');
            fclose($handle);
            $handle = null;
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException("$scope: could not atomically publish $label");
            }
            $published = true;
            @chmod($path, $mode);
            self::checkpoint($checkpoint, 'after-rename');
            self::syncDirectory($dir, "$label parent directory", $scope);
            self::checkpoint($checkpoint, 'after-dir-fsync');
            $actual = @file_get_contents($path);
            if (!is_string($actual) || !hash_equals(hash('sha256', $bytes), hash('sha256', $actual))) {
                throw new \RuntimeException("$scope: $label readback did not match published bytes");
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published) {
                @unlink($tmp);
            }
            throw $e;
        }
    }

    public static function ensureDirectory(string $path, int $mode, string $scope): void {
        if (is_link($path)) {
            throw new \RuntimeException("$scope: refusing symlink directory '$path'");
        }
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("$scope: could not create directory '$path'");
        }
        if (is_link($path) || !is_dir($path)) {
            throw new \RuntimeException("$scope: unsafe directory '$path'");
        }
        @chmod($path, $mode);
    }

    public static function syncDirectory(string $path, string $label, string $scope): void {
        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException("$scope: could not open $label for fsync");
        }
        try {
            if (!@fsync($handle)) {
                throw new \RuntimeException("$scope: could not fsync $label");
            }
        } finally {
            fclose($handle);
        }
    }

    public static function assertRegularOrAbsent(string $path, string $label, string $scope): void {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException("$scope: $label is not a regular file");
        }
    }

    public static function assertAbsoluteRegularFile(string $path, string $label, string $scope): void {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) {
            throw new \RuntimeException("$scope: $label must be an absolute regular file");
        }
    }

    private static function checkpoint(?callable $checkpoint, string $stage): void {
        if ($checkpoint !== null) {
            $checkpoint($stage);
        }
    }
}
