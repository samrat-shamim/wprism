<?php
declare(strict_types=1);

namespace Duo\Cloud;

/** Framework-neutral HTTP response emitted by the closed cloud router. */
final class CloudHttpResponse {
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {}
}
