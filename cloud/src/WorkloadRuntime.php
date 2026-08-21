<?php
declare(strict_types=1);

namespace Duo\Cloud;

/**
 * Closed boundary between lifecycle authority and an isolated workload runtime.
 *
 * PreviewSlotLifecycle serializes these calls under its authority lock and
 * persists their exact intent first. Implementations must make every mutating
 * method idempotent for the supplied tenant/site/resource/generation/operation
 * tuple. Evidence proves only that the implementation reported that stage; this
 * interface does not itself prove container, TLS, credential, or network
 * containment.
 */
interface WorkloadRuntime {
    /**
     * Read physical presence without creating, starting, routing, or deleting.
     *
     * @param array{
     *   environment_identity:string,
     *   lease_generation:int,
     *   lease_id:string,
     *   operation_id:string,
     *   ownership_receipt_sha256:string,
     *   resource_id:string,
     *   site_id:string,
     *   tenant_id:string
     * } $lease
     * @return array{evidence_sha256:string,presence:string,url:string}
     */
    public function inspect(array $lease): array;

    /**
     * Provision or converge exactly one already-reserved workload generation.
     *
     * @param array<string,mixed> $lease Exact lease shape documented by inspect().
     * @return array{evidence_sha256:string,url:string}
     */
    public function provision(array $lease): array;

    /**
     * @param array<string,mixed> $authority Exact held mutation authority.
     * @param array{database_sha256:string,media_sha256:string,snapshot_set_id:string} $snapshot
     * @return array{evidence_sha256:string}
     */
    public function restoreSnapshot(array $authority, array $snapshot): array;

    /**
     * @param array<string,mixed> $authority Exact held mutation authority.
     * @param array{branch_commit:string,branch_ref:string,repo_path:string} $repository
     * @return array{evidence_sha256:string}
     */
    public function materializeRepository(array $authority, array $repository): array;

    /**
     * @param array<string,mixed> $authority Exact held mutation authority.
     * @return array{evidence_sha256:string}
     */
    public function configureUrl(array $authority, string $url): array;

    /**
     * Remove every route to the generation before execution or state deletion.
     *
     * @param array<string,mixed> $authority Exact reaping authority.
     * @return array{evidence_sha256:string}
     */
    public function revokeRouting(array $authority): array;

    /**
     * Stop and revoke every execution path before generation state deletion.
     *
     * @param array<string,mixed> $authority Exact reaping authority.
     * @return array{evidence_sha256:string}
     */
    public function revokeExecution(array $authority): array;

    /**
     * Resume the exact retained generation after execution was revoked.
     *
     * The implementation must prove that credentials, network, volumes, and
     * storage still belong to the same lease before recreating execution. It
     * must not restore routing; PreviewSlotLifecycle orders that as a separate
     * durable stage after execution readback succeeds.
     *
     * @param array<string,mixed> $authority Exact held mutation authority.
     * @return array{evidence_sha256:string}
     */
    public function resumeExecution(array $authority): array;

    /**
     * Delete database, filesystem, credentials, and generation-owned state.
     *
     * @param array<string,mixed> $authority Exact reaping authority.
     * @return array{evidence_sha256:string}
     */
    public function deleteState(array $authority): array;

    /**
     * Prove terminal absence without mutating the physical resource.
     *
     * @param array<string,mixed> $lease Exact lease shape documented by inspect().
     * @return array{absence_proof_sha256:string,absent:bool}
     */
    public function verifyAbsent(array $lease): array;
}

/**
 * Optional read-only evidence surface implemented by reviewed preview runtimes.
 *
 * PreviewSlotLifecycle checks this interface before exposing evidence through
 * its authenticated gateway. Keeping it separate from WorkloadRuntime means a
 * generic runtime cannot accidentally claim containment merely by satisfying
 * the physical lifecycle contract.
 */
interface ReviewedPreviewBaseProvider {
    /** @return array<string,string> */
    public function reviewedBaseDescriptor(): array;

    /** @return array<string,mixed> */
    public function reviewedBaseContainmentDescriptor(): array;
}

/** Optional held-fence bridge from a pinned Git remote into the local source. */
interface RepositorySyncRuntime {
    /** @return array<string,mixed> */
    public function repositoryAuthorityDescriptor(): array;

    /**
     * @param array<string,mixed> $authority Exact held mutation authority.
     * @param array{
     *   branch_commit:string,
     *   branch_ref:string,
     *   candidate_publication_receipt_sha256:string,
     *   repository_authority_sha256:string
     * } $repository
     * @return array{evidence_sha256:string}
     */
    public function syncRepository(array $authority, array $repository): array;
}
