<?php
declare(strict_types=1);

namespace Duo\Cloud;

/**
 * Private byte storage behind the origin authority journal.
 *
 * Every address includes the full tenant/site and generation fence. A store
 * must not deduplicate across those addresses or expose existence outside the
 * exact address supplied by the already-authorized OriginAuthority caller.
 */
interface OriginBlobStore {
    public function putChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $sha256,
        string $bytes
    ): void;

    public function getChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $sha256
    ): ?string;

    public function publishArtifact(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $bytes
    ): void;

    public function getArtifact(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): ?string;

    /**
     * Delete the exact generation-fenced export scope. Implementations must be
     * idempotent because OriginAuthority persists a reaping intent before this
     * call and resumes the same deletion after a lost response or process exit.
     */
    public function deleteExport(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): void;
}
