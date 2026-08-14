<?php
declare(strict_types=1);

namespace Duo;

require_once dirname(__DIR__) . '/AtomicFilePublisher.php';
require_once dirname(__DIR__) . '/Canon.php';

/**
 * Content-addressed publication seam for immutable evidence bytes.
 * Existing bytes are never replaced with a different payload.
 */
final class EvidencePublicationBoundary {
    public static function digest(string $bytes): string {
        return hash('sha256', $bytes);
    }

    public static function publish(string $path, string $bytes, int $mode = 0600): string {
        if ($path === '' || str_contains($path, "\0") || str_ends_with($path, '/')
            || str_contains($path, '\\') || str_contains($path, '//')
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('duo evidence: publication path is malformed');
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo evidence: publication destination is unsafe');
        }
        if (is_file($path)) {
            if (Canon::read_file($path) !== $bytes) {
                throw new \RuntimeException('duo evidence: content-addressed publication already contains different bytes');
            }
            return self::digest($bytes);
        }
        AtomicFilePublisher::replace($path, $bytes, $mode);
        if (Canon::read_file($path) !== $bytes) {
            throw new \RuntimeException('duo evidence: published bytes failed readback verification');
        }
        return self::digest($bytes);
    }
}
