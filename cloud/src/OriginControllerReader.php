<?php
declare(strict_types=1);

namespace Duo\Cloud;

/**
 * Trusted in-process read seam for a later signed controller adapter.
 *
 * This deliberately has no route, envelope, callback URL, or unsigned public
 * representation. The adapter must already possess its own controller
 * authority before it is given an implementation of this interface.
 */
interface OriginControllerReader {
    /** @return array<string,mixed> */
    public function readCommittedManifest(string $tenantId, string $siteId, string $exportId): array;

    public function readCommittedChunk(
        string $tenantId,
        string $siteId,
        string $exportId,
        int $index
    ): string;
}
