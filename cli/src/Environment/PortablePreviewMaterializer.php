<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/ImmutableOciReference.php';

/**
 * Materialize one lower-fidelity cloud preview from a committed semantic export.
 *
 * This is intentionally not EnvironmentMaterializer with fabricated snapshot
 * receipts. The provider creates a reviewed clean WordPress base, materializes
 * the exact candidate Git commit, and the injected portable promotion applies
 * only the content-addressed authored-state export. Physical production DB and
 * media snapshots never enter this protocol or its receipt.
 */
final class PortablePreviewMaterializer {
    public const RECEIPT_FORMAT = 'duo-portable-preview-receipt/v1';
    public const PROMOTION_RECEIPT_FORMAT = 'duo-portable-preview-promotion-receipt/v1';
    public const REVIEWED_BASE_FORMAT = 'duo-reviewed-preview-base/v1';
    public const REVIEWED_BASE_CONTAINMENT_FORMAT = 'duo-reviewed-preview-base-containment/v1';
    public const REPOSITORY_AUTHORITY_FORMAT = 'duo-cloud-repository-authority/v1';
    public const CANDIDATE_PUBLICATION_FORMAT = 'duo-cloud-preview-candidate-publication/v1';
    public const CANDIDATE_CLEANUP_FORMAT = 'duo-cloud-preview-candidate-cleanup/v1';
    public const EXPORT_FORMAT = 'duo-cloud-origin-export-manifest/v1';

    /** Omissions that every portable preview must disclose, even when adapters provide extra seeds. */
    public const REQUIRED_FIDELITY_OMISSIONS = [
        'production-external-credentials-and-effects',
        'production-host-runtime-and-caches',
        'production-physical-database-snapshot',
        'production-physical-media-snapshot',
        'production-runtime-orders-sessions-submissions-and-queues',
    ];

    /**
     * @param array{
     *   branch:string,
     *   branch_commit:string,
     *   fidelity_omissions:list<string>,
     *   portable_export:array<string,mixed>,
     *   repository_authority:array<string,mixed>,
     *   reviewed_base:array<string,mixed>,
     *   reviewed_base_containment:array<string,mixed>,
     *   ttl_seconds:int
     * } $options
     * @param callable(array<string,mixed>,?array<string,mixed>):array<string,mixed> $publishCandidate
     * @param callable(array<string,mixed>,array<string,mixed>,?array<string,mixed>):array<string,mixed> $cleanupCandidate
     * @param callable(EnvironmentDriver,array<string,mixed>):array<string,mixed> $promote
     * @param callable(EnvironmentDriver,?array<string,mixed>):array<string,mixed> $observeBeforeRelease
     * @return array<string,mixed>
     */
    public static function materialize(
        EnvironmentDriver $targetDriver,
        EnvironmentProviderClient $targetProvider,
        EnvironmentLifecycleJournal $journal,
        array $options,
        callable $publishCandidate,
        callable $cleanupCandidate,
        callable $promote,
        callable $observeBeforeRelease
    ): array {
        if (!$targetDriver instanceof ProviderLeaseBoundEnvironmentDriver) {
            throw new \RuntimeException(
                'portable preview requires a provider-lease-bound target driver; a mutable environment alias is not sufficient'
            );
        }
        return $journal->synchronizedTarget(
            $targetDriver->name(),
            static fn(): array => self::materializeLocked(
                $targetDriver,
                $targetProvider,
                $journal,
                $options,
                $publishCandidate,
                $cleanupCandidate,
                $promote,
                $observeBeforeRelease
            )
        );
    }

    /**
     * Reuse the established compare-and-reap protocol. Portable runs publish
     * the same ownership/fence/TTL lifecycle phases, while their completion
     * receipt remains a distinct, non-substitutable product claim.
     *
     * @return array<string,mixed>
     */
    public static function reap(
        EnvironmentDriver $targetDriver,
        EnvironmentProviderClient $targetProvider,
        EnvironmentLifecycleJournal $journal,
        ?string $expectedOperationId = null
    ): array {
        return EnvironmentMaterializer::reap(
            $targetDriver,
            $targetProvider,
            $journal,
            null,
            $expectedOperationId
        );
    }

    /** @return array<string,mixed> */
    private static function materializeLocked(
        ProviderLeaseBoundEnvironmentDriver $targetDriver,
        EnvironmentProviderClient $targetProvider,
        EnvironmentLifecycleJournal $journal,
        array $options,
        callable $publishCandidate,
        callable $cleanupCandidate,
        callable $promote,
        callable $observeBeforeRelease
    ): array {
        self::assertOptions($options);
        $root = self::repositoryRoot();
        $branchCommit = self::assertCleanCandidateBranch(
            $root,
            $options['branch'],
            $options['branch_commit']
        );
        $export = $options['portable_export'];
        $base = $options['reviewed_base'];
        $containment = $options['reviewed_base_containment'];
        $repositoryAuthority = $options['repository_authority'];
        $omissions = $options['fidelity_omissions'];
        $intent = [
            'branch_commit' => $branchCommit,
            'branch_ref' => $options['branch'],
            'fidelity_omissions_sha256' => hash('sha256', EnvironmentLifecycleCanon::encode($omissions)),
            'mode' => 'create',
            'portable_export_manifest_sha256' => $export['manifest_sha256'],
            'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
            'reviewed_base_containment_sha256' => $containment['descriptor_sha256'],
            'reviewed_base_receipt_sha256' => $base['review_receipt_sha256'],
            'source_environment' => 'portable-origin-export',
            'target_environment' => $targetDriver->name(),
            'ttl_seconds' => $options['ttl_seconds'],
        ];
        $intentSha = hash('sha256', EnvironmentLifecycleCanon::encode($intent));
        $latest = $journal->latestForTarget($targetDriver->name());
        $operationId = self::operationId();
        if ($latest !== null && self::phaseData($journal, $latest['operation_id'], 'reaped') === null) {
            if (!hash_equals((string) ($latest['run']['intent_sha256'] ?? ''), $intentSha)) {
                throw new \RuntimeException(
                    "target '{$targetDriver->name()}' already has an unreaped portable preview; reap it before changing intent"
                );
            }
            $operationId = $latest['operation_id'];
            $completed = self::phaseData($journal, $operationId, 'complete');
            if ($completed !== null) {
                self::assertCompletionReceipt($completed, $operationId);
                $observation = self::phaseData($journal, $operationId, 'target-observed');
                if ($observation === null) {
                    throw new \RuntimeException('completed portable preview has no replayable bound observation evidence');
                }
                self::assertObservationReplay(
                    $observation,
                    $observeBeforeRelease($targetDriver, $observation)
                );
                return $completed + ['resumed' => true];
            }
        } else {
            $journal->start($operationId, [
                'branch_commit' => $branchCommit,
                'branch_ref' => $options['branch'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'format' => EnvironmentLifecycleJournal::RUN_FORMAT,
                'intent_sha256' => $intentSha,
                'mode' => 'create',
                'operation_id' => $operationId,
                'source_environment' => 'portable-origin-export',
                'target_environment' => $targetDriver->name(),
                'ttl_seconds' => $options['ttl_seconds'],
            ]);
        }

        try {
            $capabilities = $targetProvider->capabilities($operationId);
            $capabilities->require([
                EnvironmentProviderCapability::ENVIRONMENT_CREATE,
                EnvironmentProviderCapability::ENVIRONMENT_DESTROY,
                EnvironmentProviderCapability::ENVIRONMENT_INSPECT,
                EnvironmentProviderCapability::ENVIRONMENT_MUTATION_ACQUIRE,
                EnvironmentProviderCapability::ENVIRONMENT_MUTATION_READ,
                EnvironmentProviderCapability::ENVIRONMENT_MUTATION_RELEASE,
                EnvironmentProviderCapability::ENVIRONMENT_TTL,
                EnvironmentProviderCapability::ENVIRONMENT_TTL_READ,
                EnvironmentProviderCapability::URL_DISCOVER,
                EnvironmentProviderCapability::URL_SET,
                EnvironmentProviderCapability::REPOSITORY_MATERIALIZE,
                EnvironmentProviderCapability::REPOSITORY_SYNC,
                EnvironmentProviderCapability::OPERATION_RECEIPTS,
            ], 'materialize an isolated portable cloud preview');
            $preflight = [
                'fidelity_omissions' => $omissions,
                'portable_export' => $export,
                'repository_authority' => $repositoryAuthority,
                'reviewed_base' => $base,
                'reviewed_base_containment' => $containment,
                'target_driver' => self::driverPin($targetDriver),
                'target_provider' => $capabilities->pin(),
            ];
            self::recordPhase($journal, $operationId, 'preflight', $preflight);

            $acquireInput = [
                'intent_sha256' => $intentSha,
                'mode' => 'create',
                'target_environment' => $targetDriver->name(),
            ];
            self::recordIntent($journal, $operationId, 'target-acquire', $acquireInput);
            $identity = self::phaseData($journal, $operationId, 'target-acquired');
            if ($identity === null) {
                $created = $targetProvider->perform('create', $operationId, $acquireInput);
                self::requirePresence($created);
                self::assertProviderPin($created, $capabilities->pin());
                $identity = $created + ['mode' => 'create'];
                self::recordPhase($journal, $operationId, 'target-acquired', $identity);
            } else {
                self::requirePresence($identity);
                self::assertProviderPin($identity, $capabilities->pin());
                if (($identity['mode'] ?? null) !== 'create') {
                    throw new \RuntimeException('portable preview target is not owned through exact create authority');
                }
            }

            $owner = self::mutationOwner($operationId);
            $fenceAcquireInput = self::identityInput($identity) + ['mutation_owner' => $owner];
            self::recordIntent($journal, $operationId, 'target-fence-acquire', $fenceAcquireInput);
            $heldFence = self::phaseData($journal, $operationId, 'target-fence-acquired');
            if ($heldFence === null) {
                $heldFence = $targetProvider->perform('mutation-acquire', $operationId, $fenceAcquireInput);
                self::assertFence($heldFence, $identity, $owner, 'held');
                self::recordPhase($journal, $operationId, 'target-fence-acquired', $heldFence);
            } else {
                self::assertFence($heldFence, $identity, $owner, 'held');
            }

            $releaseIntent = self::phaseData($journal, $operationId, 'target-fence-release-intent');
            $released = self::phaseData($journal, $operationId, 'target-fence-released');
            $currentFence = $heldFence;
            if ($released !== null) {
                self::assertSameFence($heldFence, $released, true);
                if (($released['state'] ?? null) !== 'released') {
                    throw new \RuntimeException('journaled portable preview fence release is not released');
                }
                self::assertReleasedRunComplete($journal, $operationId);
                $currentFence = $released;
            } elseif ($releaseIntent !== null) {
                $releaseInput = $releaseIntent['input'] ?? null;
                $expected = self::identityInput($identity) + self::mutationInput($heldFence);
                if (($releaseIntent['action'] ?? null) !== 'target-fence-release'
                    || !is_array($releaseInput)
                    || EnvironmentLifecycleCanon::encode($releaseInput) !== EnvironmentLifecycleCanon::encode($expected)) {
                    throw new \RuntimeException('journaled portable preview fence release intent is malformed');
                }
                self::assertReleasedRunComplete($journal, $operationId);
                $released = $targetProvider->perform('mutation-release', $operationId, $releaseInput);
                self::assertSameFence($heldFence, $released, true);
                if (($released['state'] ?? null) !== 'released') {
                    throw new \RuntimeException('provider did not release the portable preview fence');
                }
                self::recordPhase($journal, $operationId, 'target-fence-released', $released);
                $currentFence = $released;
            } else {
                $read = $targetProvider->perform(
                    'mutation-read',
                    $operationId,
                    self::identityInput($identity) + self::mutationInput($heldFence)
                );
                self::assertSameFence($heldFence, $read);
                if (($read['state'] ?? null) !== 'held') {
                    throw new \RuntimeException(
                        'portable preview fence was released without an exact journaled release intent'
                    );
                }
            }

            if (($currentFence['state'] ?? null) === 'held') {
                $targetDriver->bindProviderLease($operationId, $identity, $heldFence);

                $publicationExpected = [
                    'branch_commit' => $branchCommit,
                    'branch_ref' => $repositoryAuthority['ref_prefix'] . $operationId,
                    'credential_helper_sha256' =>
                        $repositoryAuthority['credential_helper_sha256'],
                    'format' => self::CANDIDATE_PUBLICATION_FORMAT,
                    'operation_id' => $operationId,
                    'remote_url_sha256' => $repositoryAuthority['remote_url_sha256'],
                    'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
                    'status' => 'published',
                ];
                self::recordIntent(
                    $journal,
                    $operationId,
                    'candidate-publication',
                    $publicationExpected
                );
                $publication = self::phaseData($journal, $operationId, 'candidate-published');
                $cleaned = self::phaseData($journal, $operationId, 'candidate-cleaned');
                $cleanupIntent = self::phaseData(
                    $journal,
                    $operationId,
                    'candidate-cleanup-intent'
                );
                if ($cleaned === null && $cleanupIntent === null) {
                    $publication = self::invokeCandidatePublication(
                        $publishCandidate,
                        $publicationExpected,
                        $publication
                    );
                    self::recordPhase($journal, $operationId, 'candidate-published', $publication);
                } elseif ($publication === null) {
                    throw new \RuntimeException(
                        'portable preview cleaned a candidate without durable publication evidence'
                    );
                } else {
                    self::assertCandidatePublication($publication, $publicationExpected);
                }

                $syncInput = self::identityInput($identity) + self::mutationInput($heldFence) + [
                    'branch_commit' => $branchCommit,
                    'branch_ref' => $publication['branch_ref'],
                    'candidate_publication_receipt_sha256' =>
                        $publication['publication_receipt_sha256'],
                    'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
                ];
                self::recordIntent($journal, $operationId, 'repository-sync', $syncInput);
                $repositorySync = self::phaseData($journal, $operationId, 'repository-synced');
                if ($repositorySync === null) {
                    $repositorySync = $targetProvider->perform(
                        'repository-sync',
                        $operationId,
                        $syncInput
                    );
                    self::assertRepositorySync(
                        $identity,
                        $repositorySync,
                        $publication,
                        $repositoryAuthority
                    );
                    self::recordPhase($journal, $operationId, 'repository-synced', $repositorySync);
                } else {
                    self::assertRepositorySync(
                        $identity,
                        $repositorySync,
                        $publication,
                        $repositoryAuthority
                    );
                }

                self::recordIntent($journal, $operationId, 'candidate-cleanup', [
                    'branch_commit' => $branchCommit,
                    'branch_ref' => $publication['branch_ref'],
                    'candidate_publication_receipt_sha256' =>
                        $publication['publication_receipt_sha256'],
                    'repository_sync_receipt_sha256' =>
                        $repositorySync['repository_sync_receipt_sha256'],
                ]);
                $cleaned = self::invokeCandidateCleanup(
                    $cleanupCandidate,
                    $publication,
                    $repositorySync,
                    $cleaned
                );
                self::recordPhase($journal, $operationId, 'candidate-cleaned', $cleaned);

                $repositoryInput = self::identityInput($identity) + self::mutationInput($heldFence) + [
                    'branch_commit' => $branchCommit,
                    'branch_ref' => $publication['branch_ref'],
                    'expected_repository_authority_sha256' =>
                        $repositoryAuthority['descriptor_sha256'],
                    'expected_repository_sync_receipt_sha256' =>
                        $repositorySync['repository_sync_receipt_sha256'],
                    'repo_path' => $targetDriver->repoPath(),
                ];
                self::recordIntent($journal, $operationId, 'repository-materialize', $repositoryInput);
                $repository = self::phaseData($journal, $operationId, 'repository-materialized');
                if ($repository === null) {
                    $repository = $targetProvider->perform(
                        'repository-materialize',
                        $operationId,
                        $repositoryInput
                    );
                    self::assertSameIdentity($identity, $repository);
                    if (($repository['branch_commit'] ?? null) !== $branchCommit) {
                        throw new \RuntimeException('cloud provider materialized another candidate commit');
                    }
                    self::recordPhase($journal, $operationId, 'repository-materialized', $repository);
                } else {
                    self::assertSameIdentity($identity, $repository);
                    if (($repository['branch_commit'] ?? null) !== $branchCommit) {
                        throw new \RuntimeException('journaled repository evidence names another candidate commit');
                    }
                }

                $urlInput = self::identityInput($identity) + self::mutationInput($heldFence)
                    + ['url' => $identity['url']];
                self::recordIntent($journal, $operationId, 'url-set', $urlInput);
                $url = self::phaseData($journal, $operationId, 'url-set');
                if ($url === null) {
                    $url = $targetProvider->perform('url-set', $operationId, $urlInput);
                    self::assertSameIdentity($identity, $url);
                    if (($url['url'] ?? null) !== $identity['url']) {
                        throw new \RuntimeException('portable preview URL readback changed the provider-owned URL');
                    }
                    self::recordPhase($journal, $operationId, 'url-set', $url);
                }

                $promotionOwner = 'duo-portable-promotion-' . $operationId;
                $frozen = [
                    'branch_commit' => $branchCommit,
                    'branch_ref' => $options['branch'],
                    'candidate_cleanup_receipt_sha256' => $cleaned['cleanup_receipt_sha256'],
                    'candidate_publication_receipt_sha256' =>
                        $publication['publication_receipt_sha256'],
                    'fidelity_omissions' => $omissions,
                    'operation_id' => $operationId,
                    'portable_export' => $export,
                    'promotion_owner' => $promotionOwner,
                    'repository_receipt_sha256' => $repository['repository_receipt_sha256'],
                    'repository_authority' => $repositoryAuthority,
                    'repository_branch_ref' => $publication['branch_ref'],
                    'repository_sync_receipt_sha256' =>
                        $repositorySync['repository_sync_receipt_sha256'],
                    'reviewed_base' => $base,
                    'reviewed_base_containment' => $containment,
                    'target_url' => $identity['url'],
                ];
                self::recordIntent($journal, $operationId, 'portable-promotion', $frozen);
                $promotion = self::phaseData($journal, $operationId, 'portable-promotion-applied');
                if ($promotion === null) {
                    $targetDriver->beginProviderCommandPhase('portable-promotion');
                    $promotion = self::invokePromotion($promote, $targetDriver, $frozen);
                    self::recordPhase($journal, $operationId, 'portable-promotion-applied', $promotion);
                } else {
                    self::assertPromotionReceipt($promotion, $frozen);
                }

                $final = self::phaseData($journal, $operationId, 'target-final-inspected');
                if ($final === null) {
                    $final = $targetProvider->perform(
                        'inspect',
                        $operationId,
                        self::identityInput($identity) + ['role' => 'target']
                    );
                    self::requirePresence($final);
                    self::assertSameIdentity($identity, $final);
                    if (($final['url'] ?? null) !== $identity['url']) {
                        throw new \RuntimeException('portable preview URL changed during promotion');
                    }
                    self::recordPhase($journal, $operationId, 'target-final-inspected', $final);
                }

                $ttlInput = self::identityInput($identity) + self::mutationInput($heldFence)
                    + ['ttl_seconds' => $options['ttl_seconds']];
                self::recordIntent($journal, $operationId, 'ttl-set', $ttlInput);
                $ttl = self::phaseData($journal, $operationId, 'ttl-set');
                if ($ttl === null) {
                    $ttl = $targetProvider->perform('ttl-set', $operationId, $ttlInput);
                    self::assertSameIdentity($identity, $ttl);
                    self::recordPhase($journal, $operationId, 'ttl-set', $ttl);
                }
                $ttlReadInput = self::identityInput($identity) + self::mutationInput($heldFence)
                    + self::ttlInput($ttl);
                self::recordIntent($journal, $operationId, 'ttl-read', $ttlReadInput);
                $ttlRead = self::phaseData($journal, $operationId, 'ttl-read');
                if ($ttlRead === null) {
                    $ttlRead = $targetProvider->perform('ttl-read', $operationId, $ttlReadInput);
                    self::assertSameTtl($ttl, $ttlRead);
                    self::recordPhase($journal, $operationId, 'ttl-read', $ttlRead);
                } else {
                    self::assertSameTtl($ttl, $ttlRead);
                }

                $observation = self::phaseData($journal, $operationId, 'target-observed');
                if ($observation === null) {
                    $targetDriver->beginProviderCommandPhase('portable-observation');
                    $observation = $observeBeforeRelease($targetDriver, null);
                    if (!is_array($observation) || array_is_list($observation)) {
                        throw new \RuntimeException('portable preview observation must be a canonical object');
                    }
                    self::recordPhase($journal, $operationId, 'target-observed', $observation);
                } else {
                    self::assertObservationReplay(
                        $observation,
                        $observeBeforeRelease($targetDriver, $observation)
                    );
                }

                $releaseInput = self::identityInput($identity) + self::mutationInput($heldFence);
                self::recordIntent($journal, $operationId, 'target-fence-release', $releaseInput);
                $released = $targetProvider->perform('mutation-release', $operationId, $releaseInput);
                self::assertSameFence($heldFence, $released, true);
                if (($released['state'] ?? null) !== 'released') {
                    throw new \RuntimeException('provider did not release the portable preview fence');
                }
                $targetDriver->releaseProviderLease($operationId, $released);
                self::recordPhase($journal, $operationId, 'target-fence-released', $released);
            }

            $publication = self::requiredPhase($journal, $operationId, 'candidate-published');
            $repositorySync = self::requiredPhase($journal, $operationId, 'repository-synced');
            $cleaned = self::requiredPhase($journal, $operationId, 'candidate-cleaned');
            $publicationExpected = [
                'branch_commit' => $branchCommit,
                'branch_ref' => $repositoryAuthority['ref_prefix'] . $operationId,
                'credential_helper_sha256' => $repositoryAuthority['credential_helper_sha256'],
                'format' => self::CANDIDATE_PUBLICATION_FORMAT,
                'operation_id' => $operationId,
                'remote_url_sha256' => $repositoryAuthority['remote_url_sha256'],
                'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
                'status' => 'published',
            ];
            self::assertCandidatePublication($publication, $publicationExpected);
            self::assertRepositorySync(
                $identity,
                $repositorySync,
                $publication,
                $repositoryAuthority
            );
            self::assertCandidateCleanup($cleaned, $publication, $repositorySync);
            $repository = self::requiredPhase($journal, $operationId, 'repository-materialized');
            $promotion = self::requiredPhase($journal, $operationId, 'portable-promotion-applied');
            $final = self::requiredPhase($journal, $operationId, 'target-final-inspected');
            $ttl = self::requiredPhase($journal, $operationId, 'ttl-read');
            $receipt = [
                'base_image_digest' => $base['image_digest'],
                'base_containment_descriptor_sha256' => $containment['descriptor_sha256'],
                'base_platform_fingerprint_sha256' => $base['platform_fingerprint_sha256'],
                'base_review_receipt_sha256' => $base['review_receipt_sha256'],
                'branch_commit' => $branchCommit,
                'branch_ref' => $options['branch'],
                'candidate_cleanup_receipt_sha256' => $cleaned['cleanup_receipt_sha256'],
                'candidate_publication_receipt_sha256' =>
                    $publication['publication_receipt_sha256'],
                'environment_identity' => $identity['environment_identity'],
                'expires_at' => $ttl['expires_at'],
                'export_artifact_hash' => $export['artifact_hash'],
                'export_code_revision' => $export['code_revision'],
                'export_expected_production_commit' => $export['expected_production_commit'],
                'export_generation' => $export['generation'],
                'export_manifest_sha256' => $export['manifest_sha256'],
                'export_repository_revision_hash' => $export['repository_revision_hash'],
                'export_sha256' => $export['export_sha256'],
                'export_snapshot_hash' => $export['snapshot_hash'],
                'fidelity' => ['level' => 'portable-authored-state', 'omissions' => $omissions],
                'format' => self::RECEIPT_FORMAT,
                'lease_generation' => $identity['lease_generation'],
                'lease_id' => $identity['lease_id'],
                'mode' => 'create',
                'operation_id' => $operationId,
                'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
                'production_fidelity' => false,
                'promotion_receipt_sha256' => $promotion['receipt_sha256'],
                'provider' => $final['_provider'],
                'repository_receipt_sha256' => $repository['repository_receipt_sha256'],
                'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
                'repository_branch_ref' => $publication['branch_ref'],
                'repository_credential_helper_sha256' =>
                    $repositoryAuthority['credential_helper_sha256'],
                'repository_sync_receipt_sha256' =>
                    $repositorySync['repository_sync_receipt_sha256'],
                'resource_id' => $identity['resource_id'],
                'state_revision' => $promotion['state_revision'],
                'target_capabilities' => $capabilities->digest(),
                'target_provider' => $capabilities->pin(),
                'ttl_generation' => $ttl['ttl_generation'],
                'ttl_lease_id' => $ttl['ttl_lease_id'],
                'ttl_receipt_sha256' => $ttl['ttl_receipt_sha256'],
                'url' => $final['url'],
            ];
            $receipt['receipt_sha256'] = hash('sha256', EnvironmentLifecycleCanon::encode($receipt));
            self::recordPhase($journal, $operationId, 'complete', $receipt);
            return $receipt + ['resumed' => $latest !== null];
        } catch (\Throwable $e) {
            // Never invent a local/service fence release on failure. The held
            // generation is recovered by this journal or exact compare-and-reap.
            try {
                $journal->append($operationId, 'stopped', ['reason' => $e->getMessage()]);
            } catch (\Throwable) {
                // The original control/provider failure remains authoritative.
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $options */
    private static function assertOptions(array $options): void {
        self::assertExactKeys(
            $options,
            [
                'branch', 'branch_commit', 'fidelity_omissions', 'portable_export', 'reviewed_base',
                'repository_authority', 'reviewed_base_containment', 'ttl_seconds',
            ],
            'portable preview options'
        );
        if (!is_string($options['branch']) || $options['branch'] === ''
            || !self::isGitOid($options['branch_commit'])
            || !is_int($options['ttl_seconds'])
            || $options['ttl_seconds'] < 60 || $options['ttl_seconds'] > 2592000) {
            throw new \RuntimeException('portable preview branch/TTL options are malformed');
        }
        if (!is_array($options['portable_export']) || array_is_list($options['portable_export'])) {
            throw new \RuntimeException('portable preview export manifest must be an object');
        }
        self::assertExportManifest($options['portable_export']);
        if (!is_array($options['reviewed_base']) || array_is_list($options['reviewed_base'])) {
            throw new \RuntimeException('portable preview reviewed base must be an object');
        }
        self::assertReviewedBase($options['reviewed_base']);
        if (!is_array($options['reviewed_base_containment'])
            || array_is_list($options['reviewed_base_containment'])) {
            throw new \RuntimeException('portable preview reviewed-base containment must be an object');
        }
        self::assertReviewedBaseContainment(
            $options['reviewed_base_containment'],
            $options['reviewed_base']
        );
        if (!is_array($options['repository_authority'])
            || array_is_list($options['repository_authority'])) {
            throw new \RuntimeException('portable preview repository authority must be an object');
        }
        self::assertRepositoryAuthority($options['repository_authority']);
        self::assertFidelityOmissions($options['fidelity_omissions']);
    }

    /** @param array<string,mixed> $manifest */
    private static function assertExportManifest(array $manifest): void {
        self::assertExactKeys($manifest, [
            'artifact_hash', 'chunks', 'code_revision', 'expected_production_commit',
            'export_sha256', 'export_size', 'format', 'generation', 'manifest_sha256',
            'repository_revision_hash', 'snapshot_hash',
        ], 'portable preview export manifest');
        if ($manifest['format'] !== self::EXPORT_FORMAT
            || !is_int($manifest['generation']) || $manifest['generation'] < 1
            || !is_int($manifest['export_size']) || $manifest['export_size'] < 1
            || $manifest['export_size'] > 67108864
            || !self::isGitOid($manifest['expected_production_commit'])
            || !self::isHash($manifest['artifact_hash'])
            || !self::isHash($manifest['export_sha256'])
            || !self::isHash($manifest['repository_revision_hash'])
            || !self::isHash($manifest['snapshot_hash'])
            || ($manifest['code_revision'] !== null && !self::isHash($manifest['code_revision']))
            || !is_array($manifest['chunks']) || !array_is_list($manifest['chunks'])
            || $manifest['chunks'] === []) {
            throw new \RuntimeException('portable preview export manifest is malformed');
        }
        $expectedChunks = (int) ceil($manifest['export_size'] / 1048576);
        if (count($manifest['chunks']) !== $expectedChunks || $expectedChunks > 64) {
            throw new \RuntimeException('portable preview export does not use the fixed 1 MiB chunk geometry');
        }
        $offset = 0;
        foreach ($manifest['chunks'] as $index => $chunk) {
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new \RuntimeException('portable preview export chunk is malformed');
            }
            self::assertExactKeys($chunk, ['index', 'offset', 'sha256', 'size'], 'portable preview export chunk');
            if (($chunk['index'] ?? null) !== $index || ($chunk['offset'] ?? null) !== $offset
                || !is_int($chunk['size']) || $chunk['size'] < 1 || $chunk['size'] > 1048576
                || !self::isHash($chunk['sha256'] ?? null)) {
                throw new \RuntimeException('portable preview export chunks are not contiguous content-addressed records');
            }
            $expectedSize = min(1048576, $manifest['export_size'] - $offset);
            if ($chunk['size'] !== $expectedSize) {
                throw new \RuntimeException('portable preview export does not use the fixed 1 MiB chunk geometry');
            }
            $offset += $chunk['size'];
        }
        if ($offset !== $manifest['export_size']) {
            throw new \RuntimeException('portable preview export chunks do not cover the exact artifact size');
        }
        $claimed = $manifest['manifest_sha256'] ?? null;
        $basis = $manifest;
        unset($basis['manifest_sha256']);
        if (!self::isHash($claimed)
            || !hash_equals((string) $claimed, hash('sha256', \Duo\Canon::encode($basis)))) {
            throw new \RuntimeException('portable preview export manifest hash does not verify');
        }
    }

    /** @param array<string,mixed> $base */
    private static function assertReviewedBase(array $base): void {
        self::assertExactKeys($base, [
            'format', 'image_digest', 'platform_fingerprint_sha256', 'review_receipt_sha256',
        ], 'reviewed portable preview base');
        if ($base['format'] !== self::REVIEWED_BASE_FORMAT
            || !is_string($base['image_digest'])
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $base['image_digest']) !== 1
            || !self::isHash($base['platform_fingerprint_sha256'])
            || !self::isHash($base['review_receipt_sha256'])) {
            throw new \RuntimeException('portable preview base is not pinned to reviewed clean-image evidence');
        }
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $base */
    private static function assertReviewedBaseContainment(array $descriptor, array $base): void {
        self::assertExactKeys($descriptor, [
            'descriptor_sha256', 'egress_evidence', 'format', 'image_reference',
            'reviewed_base', 'routing_evidence', 'runtime_configuration_sha256',
            'seccomp_profile_sha256', 'secrets_evidence', 'storage_evidence',
        ], 'portable preview reviewed-base containment');
        if ($descriptor['format'] !== self::REVIEWED_BASE_CONTAINMENT_FORMAT
            || $descriptor['egress_evidence'] !== 'host-nft-input-forward-default-deny-readback/v1'
            || $descriptor['routing_evidence'] !== 'credential-free-route-authority-readback/v1'
            || $descriptor['secrets_evidence']
                !== 'generation-private-files-readonly-mount-readback/v1'
            || $descriptor['storage_evidence']
                !== 'dm-crypt-xfs-project-quota-exact-readback/v1'
            || !ImmutableOciReference::valid($descriptor['image_reference'] ?? null)
            || !self::isHash($descriptor['runtime_configuration_sha256'])
            || !self::isHash($descriptor['seccomp_profile_sha256'])
            || !is_array($descriptor['reviewed_base'])
            || array_is_list($descriptor['reviewed_base'])
            || EnvironmentLifecycleCanon::encode($descriptor['reviewed_base'])
                !== EnvironmentLifecycleCanon::encode($base)) {
            throw new \RuntimeException(
                'portable preview containment is not bound to the exact reviewed clean base'
            );
        }
        $at = strrpos($descriptor['image_reference'], '@');
        if (!is_int($at)
            || substr($descriptor['image_reference'], $at + 1) !== $base['image_digest']) {
            throw new \RuntimeException('portable preview containment image does not match its reviewed base');
        }
        $basis = $descriptor;
        $claimed = $basis['descriptor_sha256'] ?? null;
        unset($basis['descriptor_sha256']);
        if (!self::isHash($claimed)
            || !hash_equals(
                (string) $claimed,
                hash(
                    'sha256',
                    self::REVIEWED_BASE_CONTAINMENT_FORMAT . "\0"
                        . EnvironmentLifecycleCanon::encode($basis)
                )
            )) {
            throw new \RuntimeException('portable preview containment descriptor hash does not verify');
        }
    }

    /** @param array<string,mixed> $descriptor */
    private static function assertRepositoryAuthority(array $descriptor): void {
        self::assertExactKeys($descriptor, [
            'credential_helper_sha256', 'descriptor_sha256', 'format',
            'ref_prefix', 'remote_url_sha256',
        ], 'portable preview repository authority');
        if (($descriptor['format'] ?? null) !== self::REPOSITORY_AUTHORITY_FORMAT
            || !self::isHash($descriptor['descriptor_sha256'] ?? null)
            || !self::isHash($descriptor['credential_helper_sha256'] ?? null)
            || !self::isHash($descriptor['remote_url_sha256'] ?? null)
            || !is_string($descriptor['ref_prefix'] ?? null)
            || strlen($descriptor['ref_prefix']) > 384
            || preg_match(
                '#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*/$#D',
                $descriptor['ref_prefix']
            ) !== 1
            || str_contains($descriptor['ref_prefix'], '..')
            || str_contains($descriptor['ref_prefix'], '//')
            || str_contains($descriptor['ref_prefix'], '@{')
            || str_contains($descriptor['ref_prefix'], '.lock/')) {
            throw new \RuntimeException('portable preview repository authority is malformed');
        }
        $basis = $descriptor;
        $claimed = (string) $basis['descriptor_sha256'];
        unset($basis['descriptor_sha256']);
        if (!hash_equals(
            $claimed,
            hash(
                'sha256',
                self::REPOSITORY_AUTHORITY_FORMAT . "\0"
                    . EnvironmentLifecycleCanon::encode($basis)
            )
        )) {
            throw new \RuntimeException('portable preview repository authority hash does not verify');
        }
    }

    private static function assertFidelityOmissions(mixed $omissions): void {
        if (!is_array($omissions) || !array_is_list($omissions) || $omissions === []
            || count($omissions) !== count(array_unique($omissions))) {
            throw new \RuntimeException('portable preview fidelity omissions must be a unique list');
        }
        $sorted = $omissions;
        sort($sorted, SORT_STRING);
        if ($sorted !== $omissions) {
            throw new \RuntimeException('portable preview fidelity omissions must be canonically sorted');
        }
        foreach ($omissions as $omission) {
            if (!is_string($omission) || preg_match('/^[a-z][a-z0-9.-]{0,95}$/D', $omission) !== 1) {
                throw new \RuntimeException('portable preview fidelity omission identifier is malformed');
            }
        }
        $missing = array_diff(self::REQUIRED_FIDELITY_OMISSIONS, $omissions);
        if ($missing !== []) {
            throw new \RuntimeException(
                "portable preview fidelity report omitted mandatory gap '" . reset($missing) . "'"
            );
        }
    }

    /**
     * @param array<string,mixed> $expected
     * @param ?array<string,mixed> $prior
     * @return array<string,mixed>
     */
    private static function invokeCandidatePublication(
        callable $publish,
        array $expected,
        ?array $prior
    ): array {
        $publication = $publish($expected, $prior);
        if (!is_array($publication) || array_is_list($publication)) {
            throw new \RuntimeException('candidate publication returned no structured receipt');
        }
        self::assertCandidatePublication($publication, $expected);
        if ($prior !== null
            && EnvironmentLifecycleCanon::encode($prior)
                !== EnvironmentLifecycleCanon::encode($publication)) {
            throw new \RuntimeException('candidate publication recovery changed its durable receipt');
        }
        return $publication;
    }

    /** @param array<string,mixed> $publication @param array<string,mixed> $expected */
    private static function assertCandidatePublication(array $publication, array $expected): void {
        self::assertExactKeys($expected, [
            'branch_commit', 'branch_ref', 'credential_helper_sha256', 'format',
            'operation_id', 'remote_url_sha256', 'repository_authority_sha256', 'status',
        ], 'candidate publication expectation');
        self::assertExactKeys($publication, array_merge(
            array_keys($expected),
            ['publication_receipt_sha256']
        ), 'candidate publication receipt');
        $basis = $publication;
        $claimed = $basis['publication_receipt_sha256'] ?? null;
        unset($basis['publication_receipt_sha256']);
        if (EnvironmentLifecycleCanon::encode($basis) !== EnvironmentLifecycleCanon::encode($expected)
            || ($publication['format'] ?? null) !== self::CANDIDATE_PUBLICATION_FORMAT
            || ($publication['status'] ?? null) !== 'published'
            || !self::isGitOid($publication['branch_commit'] ?? null)
            || !self::isGitRef($publication['branch_ref'] ?? null)
            || !self::isHash($publication['credential_helper_sha256'] ?? null)
            || !self::isHash($publication['remote_url_sha256'] ?? null)
            || !self::isHash($publication['repository_authority_sha256'] ?? null)
            || !self::isHash($claimed)
            || !hash_equals(
                (string) $claimed,
                hash(
                    'sha256',
                    self::CANDIDATE_PUBLICATION_FORMAT . "\0"
                        . EnvironmentLifecycleCanon::encode($basis)
                )
            )) {
            throw new \RuntimeException('candidate publication is not bound to its exact operation ref');
        }
    }

    /**
     * @param array<string,mixed> $publication
     * @param array<string,mixed> $repositorySync
     * @param ?array<string,mixed> $prior
     * @return array<string,mixed>
     */
    private static function invokeCandidateCleanup(
        callable $cleanup,
        array $publication,
        array $repositorySync,
        ?array $prior
    ): array {
        $receipt = $cleanup($publication, $repositorySync, $prior);
        if (!is_array($receipt) || array_is_list($receipt)) {
            throw new \RuntimeException('candidate cleanup returned no structured receipt');
        }
        self::assertCandidateCleanup($receipt, $publication, $repositorySync);
        if ($prior !== null
            && EnvironmentLifecycleCanon::encode($prior) !== EnvironmentLifecycleCanon::encode($receipt)) {
            throw new \RuntimeException('candidate cleanup recovery changed its durable receipt');
        }
        return $receipt;
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $publication
     * @param array<string,mixed> $repositorySync
     */
    private static function assertCandidateCleanup(
        array $receipt,
        array $publication,
        array $repositorySync
    ): void {
        self::assertExactKeys($receipt, [
            'branch_commit', 'branch_ref', 'candidate_publication_receipt_sha256',
            'cleanup_receipt_sha256', 'format', 'operation_id',
            'repository_sync_receipt_sha256', 'status',
        ], 'candidate cleanup receipt');
        $basis = $receipt;
        $claimed = $basis['cleanup_receipt_sha256'] ?? null;
        unset($basis['cleanup_receipt_sha256']);
        $expected = [
            'branch_commit' => $publication['branch_commit'],
            'branch_ref' => $publication['branch_ref'],
            'candidate_publication_receipt_sha256' =>
                $publication['publication_receipt_sha256'],
            'format' => self::CANDIDATE_CLEANUP_FORMAT,
            'operation_id' => $publication['operation_id'],
            'repository_sync_receipt_sha256' =>
                $repositorySync['repository_sync_receipt_sha256'],
            'status' => 'absent',
        ];
        if (EnvironmentLifecycleCanon::encode($basis) !== EnvironmentLifecycleCanon::encode($expected)
            || !self::isHash($claimed)
            || !hash_equals(
                (string) $claimed,
                hash(
                    'sha256',
                    self::CANDIDATE_CLEANUP_FORMAT . "\0"
                        . EnvironmentLifecycleCanon::encode($basis)
                )
            )) {
            throw new \RuntimeException('candidate cleanup is not bound to the durable repository sync');
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $sync
     * @param array<string,mixed> $publication
     * @param array<string,mixed> $authority
     */
    private static function assertRepositorySync(
        array $identity,
        array $sync,
        array $publication,
        array $authority
    ): void {
        self::assertSameIdentity($identity, $sync);
        if (($sync['branch_commit'] ?? null) !== $publication['branch_commit']
            || ($sync['branch_ref'] ?? null) !== $publication['branch_ref']
            || ($sync['candidate_publication_receipt_sha256'] ?? null)
                !== $publication['publication_receipt_sha256']
            || ($sync['repository_authority_sha256'] ?? null) !== $authority['descriptor_sha256']
            || !self::isHash($sync['repository_sync_receipt_sha256'] ?? null)) {
            throw new \RuntimeException('repository sync is stale, foreign, or changed its candidate ref');
        }
    }

    /** @param array<string,mixed> $frozen @return array<string,mixed> */
    private static function invokePromotion(
        callable $promote,
        EnvironmentDriver $targetDriver,
        array $frozen
    ): array {
        $receipt = $promote($targetDriver, $frozen);
        if (!is_array($receipt) || array_is_list($receipt)) {
            throw new \RuntimeException('portable promotion returned no structured receipt');
        }
        self::assertPromotionReceipt($receipt, $frozen);
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $frozen */
    private static function assertPromotionReceipt(array $receipt, array $frozen): void {
        self::assertExactKeys($receipt, [
            'base_containment_descriptor_sha256', 'base_image_digest',
            'base_platform_fingerprint_sha256', 'base_review_receipt_sha256',
            'branch_commit', 'candidate_cleanup_receipt_sha256',
            'candidate_publication_receipt_sha256', 'export_manifest_sha256',
            'export_snapshot_hash', 'format', 'operation_id', 'owner', 'receipt_sha256',
            'repository_authority_sha256', 'repository_credential_helper_sha256',
            'repository_receipt_sha256',
            'repository_sync_receipt_sha256', 'state_revision', 'status',
        ], 'portable promotion receipt');
        $export = $frozen['portable_export'];
        $base = $frozen['reviewed_base'];
        if ($receipt['format'] !== self::PROMOTION_RECEIPT_FORMAT
            || $receipt['status'] !== 'completed'
            || $receipt['operation_id'] !== $frozen['operation_id']
            || $receipt['owner'] !== $frozen['promotion_owner']
            || $receipt['branch_commit'] !== $frozen['branch_commit']
            || $receipt['candidate_cleanup_receipt_sha256']
                !== $frozen['candidate_cleanup_receipt_sha256']
            || $receipt['candidate_publication_receipt_sha256']
                !== $frozen['candidate_publication_receipt_sha256']
            || $receipt['repository_authority_sha256']
                !== $frozen['repository_authority']['descriptor_sha256']
            || $receipt['repository_credential_helper_sha256']
                !== $frozen['repository_authority']['credential_helper_sha256']
            || $receipt['repository_receipt_sha256'] !== $frozen['repository_receipt_sha256']
            || $receipt['repository_sync_receipt_sha256']
                !== $frozen['repository_sync_receipt_sha256']
            || $receipt['export_manifest_sha256'] !== $export['manifest_sha256']
            || $receipt['export_snapshot_hash'] !== $export['snapshot_hash']
            || $receipt['base_image_digest'] !== $base['image_digest']
            || $receipt['base_containment_descriptor_sha256']
                !== $frozen['reviewed_base_containment']['descriptor_sha256']
            || $receipt['base_platform_fingerprint_sha256'] !== $base['platform_fingerprint_sha256']
            || $receipt['base_review_receipt_sha256'] !== $base['review_receipt_sha256']
            || !self::isHash($receipt['state_revision'])) {
            throw new \RuntimeException(
                'portable promotion receipt is not bound to the frozen export, repository, and reviewed base'
            );
        }
        $basis = $receipt;
        $claimed = $basis['receipt_sha256'] ?? null;
        unset($basis['receipt_sha256']);
        if (!self::isHash($claimed)
            || !hash_equals((string) $claimed, hash('sha256', EnvironmentLifecycleCanon::encode($basis)))) {
            throw new \RuntimeException('portable promotion receipt is malformed or unverifiable');
        }
    }

    /** @param array<string,mixed> $receipt */
    private static function assertCompletionReceipt(array $receipt, string $operationId): void {
        self::assertExactKeys($receipt, [
            'base_containment_descriptor_sha256', 'base_image_digest',
            'base_platform_fingerprint_sha256', 'base_review_receipt_sha256',
            'branch_commit', 'branch_ref', 'candidate_cleanup_receipt_sha256',
            'candidate_publication_receipt_sha256', 'environment_identity', 'expires_at',
            'export_artifact_hash', 'export_code_revision', 'export_expected_production_commit',
            'export_generation', 'export_manifest_sha256', 'export_repository_revision_hash',
            'export_sha256', 'export_snapshot_hash', 'fidelity', 'format', 'lease_generation',
            'lease_id', 'mode', 'operation_id', 'ownership_receipt_sha256', 'production_fidelity',
            'promotion_receipt_sha256', 'provider', 'receipt_sha256',
            'repository_authority_sha256', 'repository_branch_ref',
            'repository_credential_helper_sha256', 'repository_receipt_sha256',
            'repository_sync_receipt_sha256', 'resource_id',
            'state_revision', 'target_capabilities', 'target_provider', 'ttl_generation',
            'ttl_lease_id', 'ttl_receipt_sha256', 'url',
        ], 'portable preview completion receipt');
        if (($receipt['format'] ?? null) !== self::RECEIPT_FORMAT
            || ($receipt['operation_id'] ?? null) !== $operationId
            || ($receipt['production_fidelity'] ?? null) !== false
            || !self::isHash($receipt['base_containment_descriptor_sha256'] ?? null)
            || !self::isHash($receipt['candidate_cleanup_receipt_sha256'] ?? null)
            || !self::isHash($receipt['candidate_publication_receipt_sha256'] ?? null)
            || !self::isHash($receipt['repository_authority_sha256'] ?? null)
            || !self::isHash($receipt['repository_credential_helper_sha256'] ?? null)
            || !self::isHash($receipt['repository_sync_receipt_sha256'] ?? null)
            || !self::isGitRef($receipt['repository_branch_ref'] ?? null)
            || !is_array($receipt['fidelity'] ?? null)
            || array_is_list($receipt['fidelity'])
            || array_keys($receipt['fidelity']) !== ['level', 'omissions']
            || ($receipt['fidelity']['level'] ?? null) !== 'portable-authored-state') {
            throw new \RuntimeException('journaled portable preview completion receipt is malformed');
        }
        self::assertFidelityOmissions($receipt['fidelity']['omissions'] ?? null);
        $basis = $receipt;
        $claimed = $basis['receipt_sha256'] ?? null;
        unset($basis['receipt_sha256']);
        if (!self::isHash($claimed)
            || !hash_equals((string) $claimed, hash('sha256', EnvironmentLifecycleCanon::encode($basis)))) {
            throw new \RuntimeException('journaled portable preview completion receipt does not verify');
        }
    }

    private static function assertReleasedRunComplete(
        EnvironmentLifecycleJournal $journal,
        string $operationId
    ): void {
        foreach ([
            'candidate-published', 'repository-synced', 'candidate-cleaned',
            'repository-materialized', 'url-set', 'portable-promotion-applied',
            'target-final-inspected', 'ttl-set', 'ttl-read', 'target-observed',
        ] as $event) {
            if (self::phaseData($journal, $operationId, $event) === null) {
                throw new \RuntimeException(
                    "portable preview fence was released before completed phase '$event'"
                );
            }
        }
    }

    /** @param array<string,mixed> $identity */
    private static function requirePresence(array $identity): void {
        if (($identity['presence'] ?? 'present') !== 'present') {
            throw new \RuntimeException('cloud provider reports that the portable preview resource is absent');
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private static function assertSameIdentity(array $expected, array $actual): void {
        foreach ([
            'environment_identity', 'resource_id', 'lease_id', 'lease_generation',
            'ownership_receipt_sha256',
        ] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new \RuntimeException(
                    "cloud provider $key changed; refusing stale or foreign portable-preview mutation"
                );
            }
        }
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $pin */
    private static function assertProviderPin(array $result, array $pin): void {
        if (!is_array($result['_provider'] ?? null) || !is_array($pin['provider'] ?? null)
            || EnvironmentLifecycleCanon::encode($result['_provider'])
                !== EnvironmentLifecycleCanon::encode($pin['provider'])) {
            throw new \RuntimeException('cloud provider identity does not match the journaled provider pin');
        }
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function identityInput(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    private static function mutationOwner(string $operationId): string {
        // This owner is shared with EnvironmentMaterializer's exact reap path.
        return 'duo-env-materialize-' . $operationId;
    }

    /** @param array<string,mixed> $fence @return array<string,mixed> */
    private static function mutationInput(array $fence): array {
        return [
            'expected_mutation_generation' => $fence['mutation_generation'],
            'expected_mutation_id' => $fence['mutation_id'],
            'expected_mutation_owner' => $fence['mutation_owner'],
            'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $fence @param array<string,mixed> $identity */
    private static function assertFence(
        array $fence,
        array $identity,
        string $owner,
        string $state
    ): void {
        self::assertSameIdentity($identity, $fence);
        if (($fence['mutation_owner'] ?? null) !== $owner || ($fence['state'] ?? null) !== $state) {
            throw new \RuntimeException('cloud provider returned a foreign portable-preview mutation fence');
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private static function assertSameFence(
        array $expected,
        array $actual,
        bool $allowReleaseReceipt = false
    ): void {
        self::assertSameIdentity($expected, $actual);
        foreach (['mutation_generation', 'mutation_id', 'mutation_owner'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new \RuntimeException("portable-preview mutation fence changed at $key");
            }
        }
        $release = $allowReleaseReceipt
            && ($expected['state'] ?? null) === 'held'
            && ($actual['state'] ?? null) === 'released';
        if (!$release
            && ($expected['mutation_receipt_sha256'] ?? null)
                !== ($actual['mutation_receipt_sha256'] ?? null)) {
            throw new \RuntimeException('portable-preview mutation fence rotated its readback receipt');
        }
    }

    /** @param array<string,mixed> $ttl @return array<string,mixed> */
    private static function ttlInput(array $ttl): array {
        return [
            'expected_expires_at' => $ttl['expires_at'],
            'expected_ttl_generation' => $ttl['ttl_generation'],
            'expected_ttl_lease_id' => $ttl['ttl_lease_id'],
            'expected_ttl_receipt_sha256' => $ttl['ttl_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private static function assertSameTtl(array $expected, array $actual): void {
        self::assertSameIdentity($expected, $actual);
        foreach (['expires_at', 'ttl_generation', 'ttl_lease_id', 'ttl_receipt_sha256', 'ttl_state'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new \RuntimeException("portable-preview TTL readback changed at $key");
            }
        }
        if (($actual['ttl_state'] ?? null) !== 'active') {
            throw new \RuntimeException('portable-preview TTL is not active');
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private static function assertObservationReplay(array $expected, array $actual): void {
        if (array_is_list($actual)
            || EnvironmentLifecycleCanon::encode($expected) !== EnvironmentLifecycleCanon::encode($actual)) {
            throw new \RuntimeException('replayed portable-preview observation differs from journaled evidence');
        }
    }

    /** @return array{environment:string,id:string,repo_path:string} */
    private static function driverPin(EnvironmentDriver $driver): array {
        return [
            'environment' => $driver->name(),
            'id' => $driver->driverId(),
            'repo_path' => $driver->repoPath(),
        ];
    }

    /** @return array<string,mixed> */
    private static function requiredPhase(
        EnvironmentLifecycleJournal $journal,
        string $operationId,
        string $event
    ): array {
        $data = self::phaseData($journal, $operationId, $event);
        if ($data === null) {
            throw new \RuntimeException("portable preview is missing completed phase '$event'");
        }
        return $data;
    }

    /** @return ?array<string,mixed> */
    private static function phaseData(
        EnvironmentLifecycleJournal $journal,
        string $operationId,
        string $event
    ): ?array {
        $events = $journal->events($operationId);
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) === $event) {
                return $events[$index]['data'];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private static function recordPhase(
        EnvironmentLifecycleJournal $journal,
        string $operationId,
        string $event,
        array $data
    ): void {
        $existing = self::phaseData($journal, $operationId, $event);
        if ($existing === null) {
            $journal->append($operationId, $event, $data);
            return;
        }
        if (EnvironmentLifecycleCanon::encode($existing) !== EnvironmentLifecycleCanon::encode($data)) {
            throw new \RuntimeException(
                "portable preview '$operationId' phase '$event' differs from exact recovery evidence"
            );
        }
    }

    /** @param array<string,mixed> $input */
    private static function recordIntent(
        EnvironmentLifecycleJournal $journal,
        string $operationId,
        string $action,
        array $input
    ): void {
        self::recordPhase($journal, $operationId, $action . '-intent', [
            'action' => $action,
            'input' => $input,
            'input_sha256' => hash('sha256', EnvironmentLifecycleCanon::encode($input)),
        ]);
    }

    private static function repositoryRoot(): string {
        return self::gitStdout(getcwd() ?: '.', ['rev-parse', '--show-toplevel']);
    }

    private static function assertCleanCandidateBranch(
        string $root,
        string $requestedBranch,
        string $expectedCommit
    ): string {
        $currentBranch = self::gitStdout($root, ['symbolic-ref', '--short', 'HEAD']);
        if ($currentBranch === '') {
            throw new \RuntimeException('portable preview requires a clean attached operator checkout');
        }
        if (hash_equals($requestedBranch, $currentBranch)) {
            throw new \RuntimeException(
                'portable preview candidate branch must remain isolated from the operator checkout'
            );
        }
        if (self::gitStdout($root, ['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
            throw new \RuntimeException('portable preview requires a clean source checkout');
        }
        $head = self::gitStdout($root, ['rev-parse', '--verify', 'HEAD^{commit}']);
        if (!self::isGitOid($head)) {
            throw new \RuntimeException('portable preview operator checkout has no exact commit');
        }
        $ref = self::gitStdout(
            $root,
            ['rev-parse', '--verify', 'refs/heads/' . $requestedBranch . '^{commit}']
        );
        if (!self::isGitOid($ref) || !hash_equals($expectedCommit, $ref)) {
            throw new \RuntimeException(
                'portable preview candidate ref does not identify the exact refresh result commit'
            );
        }
        return $ref;
    }

    /** @param list<string> $arguments */
    private static function gitStdout(string $root, array $arguments): string {
        $process = proc_open(
            array_merge(['git', '--no-optional-locks', '-C', $root], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start Git for portable preview validation');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('could not validate the portable preview Git branch');
        }
        return trim($stdout);
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has an open or incomplete schema");
        }
    }

    private static function isHash(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function isGitOid(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) === 1;
    }

    private static function isGitRef(mixed $value): bool {
        return is_string($value)
            && strlen($value) <= 512
            && preg_match('#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $value) === 1
            && !str_contains($value, '..')
            && !str_contains($value, '//')
            && !str_contains($value, '@{')
            && !str_ends_with($value, '.')
            && !str_ends_with($value, '/')
            && !str_ends_with($value, '.lock');
    }

    /** Last emitted local microsecond, preserving latestForTarget lexical order. */
    private static int $lastOperationMicros = 0;

    private static function operationId(): string {
        $micros = (int) floor(microtime(true) * 1000000);
        if ($micros <= self::$lastOperationMicros) {
            $micros = self::$lastOperationMicros + 1;
        }
        self::$lastOperationMicros = $micros;
        return gmdate('Ymd-His', intdiv($micros, 1000000))
            . '-' . sprintf('%016x', $micros) . bin2hex(random_bytes(4));
    }
}
