<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Shared exclusive lock protocol for independent recovery resources.
 *
 * Callers supply domain-specific diagnostics; ownership and release ordering
 * remain centralized so a bundle cannot accidentally forget to unlock on a
 * thrown provider or validation error.
 */
final class ProtocolLock {
    /** @template T @param callable():T $callback @return T */
    public static function withExclusive(
        string $path,
        callable $callback,
        string $unsafeMessage,
        string $openMessage,
        string $acquireMessage,
        ?int $mode = null
    ): mixed {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException($unsafeMessage);
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException($openMessage);
        }
        if ($mode !== null) {
            @chmod($path, $mode);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException($acquireMessage);
            }
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
