<?php
declare(strict_types=1);

namespace Duo;

/** Same-directory durable replacement for authoritative single-file state. */
final class AtomicFilePublisher {
    public static function replace(string $path, string $bytes, int $mode = 0600): void {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \RuntimeException('duo: atomic publication path is invalid');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("duo: cannot create directory $dir");
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo: atomic publication destination is not a regular file');
        }
        if ($mode < 0 || $mode > 0777) {
            throw new \RuntimeException('duo: atomic publication mode is invalid');
        }
        $existingMode = file_exists($path) ? (fileperms($path) & 0777) : null;
        $publishMode = $existingMode ?? $mode;

        $tmp = $dir . '/.' . basename($path) . '.duo-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($tmp, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cannot create atomic publication staging file');
        }
        $published = false;
        try {
            if (!chmod($tmp, $publishMode) || (fileperms($tmp) & 0777) !== $publishMode) {
                throw new \RuntimeException('duo: atomic publication could not secure staging permissions');
            }
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException('duo: atomic publication staging write failed');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('duo: atomic publication staging sync failed');
            }
            if (getenv('DUO_TEST_MODE') === '1'
                && getenv('DUO_TEST_ATOMIC_FILE_FAIL_PHASE') === 'before-rename') {
                throw new \RuntimeException('duo: injected atomic publication failure before rename');
            }
            fclose($handle);
            $handle = null;
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('duo: atomic publication replacement failed');
            }
            $published = true;

            // Persist the directory entry when the platform supports syncing
            // directory handles. Some PHP/filesystem combinations reject the
            // handle; the already-durable file replacement remains valid.
            $directory = @fopen($dir, 'r');
            if (is_resource($directory)) {
                if (function_exists('fsync')) {
                    @fsync($directory);
                }
                fclose($directory);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published && (file_exists($tmp) || is_link($tmp))) {
                @unlink($tmp);
            }
        }
    }
}
