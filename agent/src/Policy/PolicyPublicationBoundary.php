<?php
declare(strict_types=1);

namespace Duo\Policy;

require_once dirname(__DIR__) . '/AtomicFilePublisher.php';

/** Single-file publication seam for policy-derived authoritative bytes. */
final class PolicyPublicationBoundary {
    public static function publish(string $path, string $bytes, int $mode = 0600): void {
        \Duo\AtomicFilePublisher::replace($path, $bytes, $mode);
    }
}
