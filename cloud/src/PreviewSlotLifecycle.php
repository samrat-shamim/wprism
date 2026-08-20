<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/FileAuthorityStore.php';
require_once __DIR__ . '/ImmutableOciReference.php';
require_once __DIR__ . '/MutationAuthorityPublisher.php';
require_once __DIR__ . '/WorkloadRuntime.php';

/**
 * Durable authority for one reusable physical preview slot per tenant/site.
 *
 * This class owns lifecycle identity, replay, fences, TTL metadata, and ordered
 * reap intent. WorkloadRuntime owns the actual physical operations. Accepting a
 * runtime evidence digest here is not a claim that Docker, TLS, credentials, or
 * network containment have been proved.
 *
 * FileAuthorityStore must be backed by a lifecycle-only state file. Its format
 * check rejects accidental sharing with ControlAuthority. When command
 * authority is configured, the only lock order is lifecycle -> command
 * authority; a command already holding the command lock therefore completes
 * before a fence release can be published.
 */
final class PreviewSlotLifecycle {
    private const STORE_FORMAT = 'duo-cloud-preview-slot-store/v1';
    // One materialization can legitimately consume several independently
    // bounded 300-second runtime stages before final TTL publication.
    private const ACQUISITION_TTL_SECONDS = 3600;
    private const REVIEWED_BASE_FORMAT = 'duo-reviewed-preview-base/v1';
    private const REVIEWED_CONTAINMENT_FORMAT = 'duo-reviewed-preview-base-containment/v1';
    private const REPOSITORY_AUTHORITY_FORMAT = 'duo-cloud-repository-authority/v1';
    private const OPERATIONS_PER_GENERATION_LIMIT = 128;
    private const REGULAR_OPERATIONS_PER_GENERATION_LIMIT = 124;
    private const OPERATIONS_PER_GENERATION_BYTES_LIMIT = 1048576;
    private const RESULT_BYTES_LIMIT = 65536;
    private const MAX_CACHED_RESULT_BASE64_BYTES = 87384;
    private const ACTIONS = [
        'create',
        'destroy',
        'inspect',
        'mutation-acquire',
        'mutation-read',
        'mutation-release',
        'repository-materialize',
        'repository-sync',
        'sleep',
        'snapshot-restore',
        'ttl-read',
        'ttl-set',
        'url-set',
        'wake',
    ];

    private FileAuthorityStore $store;
    private WorkloadRuntime $runtime;
    private ?MutationAuthorityPublisher $publisher;
    /** @var \Closure():int */
    private \Closure $clock;

    public function __construct(
        FileAuthorityStore $store,
        WorkloadRuntime $runtime,
        ?MutationAuthorityPublisher $publisher = null,
        ?callable $clock = null
    ) {
        $this->store = $store;
        $this->runtime = $runtime;
        $this->publisher = $publisher;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
    }

    /**
     * Read the immutable clean-base and containment attestation without
     * acquiring a site slot or touching any workload generation.
     *
     * @return array<string,mixed>
     */
    public function reviewedBaseContainmentDescriptor(): array {
        if (!$this->runtime instanceof ReviewedPreviewBaseProvider) {
            throw new ControlRefusal('preview workload runtime has no reviewed-base containment evidence');
        }
        $descriptor = $this->runtimeCall(
            'reviewed-base evidence read',
            fn (): array => $this->runtime->reviewedBaseContainmentDescriptor()
        );
        self::assertReviewedBaseContainment($descriptor);
        return $descriptor;
    }

    /**
     * Read the credential-free Git authority that is pinned to this worker.
     * The descriptor contains no remote name, URL, path, or credential.
     *
     * @return array<string,mixed>
     */
    public function repositoryAuthorityDescriptor(): array {
        if (!$this->runtime instanceof RepositorySyncRuntime) {
            throw new ControlRefusal('preview workload runtime has no configured repository authority');
        }
        $descriptor = $this->runtimeCall(
            'repository authority evidence read',
            fn (): array => $this->runtime->repositoryAuthorityDescriptor()
        );
        self::assertRepositoryAuthority($descriptor);
        return $descriptor;
    }

    /**
     * Execute one closed provider action and return EnvironmentLifecycle-shaped evidence.
     *
     * `action + tenant + site + operation` is the replay identity. An exact
     * retry returns byte-cached canonical evidence. Reusing that identity with
     * changed input refuses before any WorkloadRuntime method is called.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function perform(
        string $action,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input
    ): array {
        self::action($action);
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::operationId($operationId);
        self::validateInput($action, $input);
        $requestHash = self::hashCanonical('duo-cloud-preview-slot-request/v1', [
            'action' => $action,
            'input' => $input,
            'operation_id' => $operationId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ]);
        $receiptKey = self::receiptKey($tenantId, $siteId, $action, $operationId);

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $action,
            $tenantId,
            $siteId,
            $operationId,
            $input,
            $requestHash,
            $receiptKey
        ): array {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $compacted = self::compactOperations($state);
            if ($compacted) {
                $session->save($state);
            }
            $receipt = $state['operations'][$receiptKey] ?? null;
            if ($receipt !== null) {
                if (!is_array($receipt)
                    || ($receipt['action'] ?? null) !== $action
                    || ($receipt['tenant_id'] ?? null) !== $tenantId
                    || ($receipt['site_id'] ?? null) !== $siteId
                    || ($receipt['operation_id'] ?? null) !== $operationId
                    || !hash_equals((string) ($receipt['request_sha256'] ?? ''), $requestHash)) {
                    throw new ControlRefusal('lifecycle operation was replayed with changed canonical input');
                }
                if (($receipt['status'] ?? null) === 'complete') {
                    return self::cachedResult($receipt);
                }
                if (($receipt['status'] ?? null) === 'superseded') {
                    throw new ControlRefusal('lifecycle operation was superseded by exact destroy');
                }
                if (($receipt['status'] ?? null) !== 'executing') {
                    throw new ControlRefusal('lifecycle operation receipt has an invalid state');
                }
            }

            return match ($action) {
                'create' => $this->create(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'inspect' => $this->inspect(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'mutation-acquire' => $this->mutationAcquire(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'mutation-read' => $this->mutationRead(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'mutation-release' => $this->mutationRelease(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'snapshot-restore', 'repository-sync', 'repository-materialize', 'url-set' => $this->mutateWorkload(
                    $session,
                    $state,
                    $action,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'sleep' => $this->sleep(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'wake' => $this->wake(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'ttl-set' => $this->ttlSet(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'ttl-read' => $this->ttlRead(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
                'destroy' => $this->destroy(
                    $session,
                    $state,
                    $tenantId,
                    $siteId,
                    $operationId,
                    $input,
                    $requestHash,
                    $receiptKey,
                    $receipt
                ),
            };
        });
    }

    /**
     * Compare one exact service deadline and destroy while the lifecycle lock
     * still excludes its atomic replacement. The identity-only input is
     * intentionally not the public destroy contract: expiry must also recover
     * generations that crashed before controller mutation authority existed.
     *
     * @param array<string,mixed> $destroyInput Exact generation identity plus compare-and-reap.
     * @param array{expires_at:string,generation:int,lease_id:string,operation_id:string,receipt_sha256:string,state:string} $expectedTtl
     * @return array<string,mixed>
     */
    public function reapExpired(
        string $tenantId,
        string $siteId,
        string $operationId,
        array $destroyInput,
        array $expectedTtl
    ): array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::operationId($operationId);
        self::validateExpiredReapInput($destroyInput);
        self::exactKeys(
            $expectedTtl,
            ['expires_at', 'generation', 'lease_id', 'operation_id', 'receipt_sha256', 'state'],
            'expired TTL comparison'
        );
        self::timestamp($expectedTtl['expires_at'] ?? null);
        self::positiveInt($expectedTtl['generation'] ?? null, 'expired TTL generation');
        self::identifier($expectedTtl['lease_id'] ?? null, 'expired TTL lease id');
        self::operationId($expectedTtl['operation_id'] ?? null);
        self::sha256($expectedTtl['receipt_sha256'] ?? null, 'expired TTL receipt');
        if (!in_array($expectedTtl['state'] ?? null, ['active', 'provisional'], true)) {
            throw new ControlRefusal('expired TTL state is invalid');
        }
        $requestHash = self::hashCanonical('duo-cloud-preview-slot-request/v1', [
            'action' => 'destroy',
            'input' => $destroyInput,
            'operation_id' => $operationId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ]);
        $receiptKey = self::receiptKey($tenantId, $siteId, 'destroy', $operationId);

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $operationId,
            $destroyInput,
            $expectedTtl,
            $requestHash,
            $receiptKey
        ): array {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $compacted = self::compactOperations($state);
            if ($compacted) {
                $session->save($state);
            }
            $receipt = $state['operations'][$receiptKey] ?? null;
            if ($receipt !== null) {
                if (!is_array($receipt)
                    || ($receipt['action'] ?? null) !== 'destroy'
                    || ($receipt['tenant_id'] ?? null) !== $tenantId
                    || ($receipt['site_id'] ?? null) !== $siteId
                    || ($receipt['operation_id'] ?? null) !== $operationId
                    || !hash_equals((string) ($receipt['request_sha256'] ?? ''), $requestHash)) {
                    throw new ControlRefusal('expired reap operation was replayed with changed canonical input');
                }
                if (($receipt['status'] ?? null) === 'complete') {
                    return self::cachedResult($receipt);
                }
                if (($receipt['status'] ?? null) !== 'executing') {
                    throw new ControlRefusal('expired reap operation receipt has an invalid state');
                }
            }
            $site = $state['sites'][self::siteKey($tenantId, $siteId)] ?? null;
            $current = is_array($site) ? ($site['current'] ?? null) : null;
            $ttl = is_array($current) ? ($current['ttl'] ?? null) : null;
            if (!is_array($current) || !is_array($ttl)
                || CanonicalJson::encode($ttl) !== CanonicalJson::encode($expectedTtl)) {
                throw new ControlRefusal('expired reap TTL comparison is stale or foreign');
            }
            self::assertIdentityInput($destroyInput, $current);
            $expiresAt = strtotime((string) $ttl['expires_at']);
            $now = ($this->clock)();
            if (!is_int($expiresAt) || !is_int($now) || $now < 0 || $expiresAt > $now) {
                throw new ControlRefusal('preview TTL is not expired at the service clock');
            }
            return $this->destroy(
                $session,
                $state,
                $tenantId,
                $siteId,
                $operationId,
                $destroyInput,
                $requestHash,
                $receiptKey,
                $receipt,
                true
            );
        });
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function create(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        $siteKey = self::siteKey($tenantId, $siteId);
        $site = $state['sites'][$siteKey] ?? self::newSite($tenantId, $siteId);
        if (!is_array($site)) {
            throw new ControlRefusal('preview slot site record is corrupt');
        }
        $current = $site['current'] ?? null;
        if ($receipt === null) {
            if ($current !== null) {
                throw new ControlRefusal('tenant/site preview slot is already owned or has incomplete reap');
            }
            self::assertNoSiteExecutingOperation($state, $tenantId, $siteId);
            $lastGeneration = $site['last_lease_generation'] ?? null;
            if (!is_int($lastGeneration) || $lastGeneration < 0 || $lastGeneration === PHP_INT_MAX) {
                throw new ControlRefusal('preview slot lease generation is invalid or exhausted');
            }
            $generation = $lastGeneration + 1;
            $identity = self::newIdentity(
                $tenantId,
                $siteId,
                $generation,
                $operationId,
                $requestHash
            );
            $probeIdentity = is_array($site['terminal_absence'] ?? null)
                ? self::identityFromRecord($site['terminal_absence'])
                : $identity;
            $absence = $this->runtimeCall(
                'pre-create absence verification',
                fn (): array => $this->runtime->verifyAbsent(
                    self::runtimeLease($probeIdentity, $tenantId, $siteId, $operationId)
                )
            );
            self::absenceEvidence($absence);
            if ($absence['absent'] !== true) {
                throw new ControlRefusal('preview slot terminal absence was not verified before reuse');
            }
            $now = ($this->clock)();
            if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - self::ACQUISITION_TTL_SECONDS) {
                throw new ControlRefusal('preview acquisition TTL clock is invalid or exhausted');
            }
            $provisionalTtl = self::newTtl(
                $identity,
                $tenantId,
                $siteId,
                $operationId,
                1,
                $now + self::ACQUISITION_TTL_SECONDS,
                'provisional'
            );
            $current = $identity + [
                'acquisition_input_sha256' => $requestHash,
                'acquisition_operation_id' => $operationId,
                'create_evidence_sha256' => null,
                'evidence' => [],
                'last_mutation_generation' => 0,
                'last_ttl_generation' => 1,
                'mutation' => null,
                'prior_absence_proof_sha256' => $absence['absence_proof_sha256'],
                'reap' => null,
                'sleep' => null,
                'state' => 'acquiring',
                'target_environment' => $input['target_environment'],
                'ttl' => $provisionalTtl,
                'url' => null,
            ];
            $site['current'] = $current;
            $site['last_lease_generation'] = $generation;
            $state['sites'][$siteKey] = $site;
            self::reserveOperation(
                $state,
                $receiptKey,
                'create',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $identity
            );
            $session->save($state);
        } else {
            if (!is_array($current)
                || ($current['state'] ?? null) !== 'acquiring'
                || ($current['acquisition_operation_id'] ?? null) !== $operationId
                || ($current['acquisition_input_sha256'] ?? null) !== $requestHash) {
                throw new ControlRefusal('incomplete create does not match current preview slot acquisition');
            }
            self::assertReceiptIdentity($receipt, $current);
        }

        $identity = self::identityFromRecord($current);
        $lease = self::runtimeLease($identity, $tenantId, $siteId, $operationId);
        $provision = $this->runtimeCall(
            'provision',
            fn (): array => $this->runtime->provision($lease)
        );
        self::provisionEvidence($provision);
        $observed = $this->runtimeCall(
            'post-provision inspection',
            fn (): array => $this->runtime->inspect($lease)
        );
        self::inspectionEvidence($observed);
        if ($observed['presence'] !== 'present' || $observed['url'] !== $provision['url']) {
            throw new ControlRefusal('provisioned preview slot did not read back as the exact present URL');
        }

        $current['create_evidence_sha256'] = self::hashCanonical(
            'duo-cloud-preview-create-evidence/v1',
            ['inspection' => $observed, 'provision' => $provision]
        );
        $current['state'] = 'present';
        $current['url'] = $observed['url'];
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        $result = self::identityResult($current) + ['presence' => 'present'];
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function inspect(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        $site = $state['sites'][self::siteKey($tenantId, $siteId)] ?? self::newSite($tenantId, $siteId);
        if (!is_array($site)) {
            throw new ControlRefusal('preview slot site record is corrupt');
        }
        $current = $site['current'] ?? null;
        if (is_array($current)) {
            if (($current['state'] ?? null) === 'acquiring') {
                throw new ControlRefusal('preview slot acquisition is incomplete; retry the exact create');
            }
            if (($current['state'] ?? null) === 'reaping') {
                throw new ControlRefusal('preview slot reap is incomplete; retry the exact destroy');
            }
            if (($current['state'] ?? null) === 'asleep') {
                throw new ControlRefusal('preview slot is asleep; wake it before inspection');
            }
            if (in_array($current['state'] ?? null, ['sleeping', 'waking'], true)) {
                throw new ControlRefusal('preview slot sleep/wake transition is incomplete; retry it exactly');
            }
            if (($current['state'] ?? null) !== 'present') {
                throw new ControlRefusal('preview slot has an invalid current state');
            }
            $identity = self::identityFromRecord($current);
            $expectedPresence = 'present';
            $expectedUrl = $current['url'];
        } elseif (is_array($site['terminal_absence'] ?? null)) {
            $identity = self::identityFromRecord($site['terminal_absence']);
            $expectedPresence = 'absent';
            $expectedUrl = $site['terminal_absence']['url'];
        } else {
            $generation = max(1, (int) ($site['last_lease_generation'] ?? 0) + 1);
            $identity = self::newIdentity($tenantId, $siteId, $generation, $operationId, $requestHash);
            $expectedPresence = 'absent';
            $expectedUrl = null;
        }
        self::assertOptionalIdentityInput($input, $identity);
        if ($receipt === null) {
            self::reserveOperation(
                $state,
                $receiptKey,
                'inspect',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $identity
            );
            $session->save($state);
        } else {
            self::assertReceiptIdentity($receipt, $identity);
        }
        $observed = $this->runtimeCall(
            'inspection',
            fn (): array => $this->runtime->inspect(
                self::runtimeLease($identity, $tenantId, $siteId, $operationId)
            )
        );
        self::inspectionEvidence($observed);
        if ($observed['presence'] !== $expectedPresence
            || ($expectedUrl !== null && $observed['url'] !== $expectedUrl)) {
            throw new ControlRefusal('physical preview-slot presence differs from lifecycle authority');
        }
        $result = $identity + ['presence' => $expectedPresence, 'url' => $observed['url']];
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function mutationAcquire(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentPresent($state, $tenantId, $siteId);
        self::assertIdentityInput($input, $current);
        if ($receipt === null) {
            self::assertNoForeignExecutingOperation($state, $current, $receiptKey);
            $mutation = $current['mutation'] ?? null;
            if (is_array($mutation) && ($mutation['state'] ?? null) !== 'released') {
                throw new ControlRefusal('preview slot already has a held or transitioning mutation fence');
            }
            if (is_array($mutation)) {
                // EnvironmentMaterializer deliberately releases ordinary
                // command authority before acquiring its separate destructive
                // reap principal. The transition is allowed only for the same
                // journal operation; arbitrary owner replacement on a stable
                // physical lease remains a refusal.
                self::assertReapRefenceOwner($mutation['owner'] ?? null, $input['mutation_owner']);
            }
            $lastGeneration = $current['last_mutation_generation'] ?? null;
            if (!is_int($lastGeneration) || $lastGeneration < 0 || $lastGeneration === PHP_INT_MAX) {
                throw new ControlRefusal('preview mutation generation is invalid or exhausted');
            }
            $generation = $lastGeneration + 1;
            $mutationId = 'cloud-mutation-' . hash(
                'sha256',
                "duo-cloud-preview-mutation/v1\0$tenantId\0$siteId\0{$current['resource_id']}\0"
                . "{$current['lease_generation']}\0$generation\0$operationId"
            );
            $heldReceipt = self::hashCanonical('duo-cloud-preview-held-mutation/v1', [
                'environment_identity' => $current['environment_identity'],
                'lease_generation' => $current['lease_generation'],
                'lease_id' => $current['lease_id'],
                'mutation_generation' => $generation,
                'mutation_id' => $mutationId,
                'mutation_owner' => $input['mutation_owner'],
                'operation_id' => $operationId,
                'ownership_receipt_sha256' => $current['ownership_receipt_sha256'],
                'resource_id' => $current['resource_id'],
                'site_id' => $siteId,
                'tenant_id' => $tenantId,
            ]);
            $mutation = [
                'generation' => $generation,
                'held_receipt_sha256' => $heldReceipt,
                'id' => $mutationId,
                'operation_id' => $operationId,
                'owner' => $input['mutation_owner'],
                'receipt_sha256' => $heldReceipt,
                'state' => 'publishing',
            ];
            $current['last_mutation_generation'] = $generation;
            $current['mutation'] = $mutation;
            $site['current'] = $current;
            $state['sites'][$siteKey] = $site;
            self::reserveOperation(
                $state,
                $receiptKey,
                'mutation-acquire',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
            $session->save($state);
        } else {
            self::assertReceiptIdentity($receipt, $current);
            $mutation = $current['mutation'] ?? null;
            if (!is_array($mutation)
                || ($mutation['state'] ?? null) !== 'publishing'
                || ($mutation['operation_id'] ?? null) !== $operationId
                || ($mutation['owner'] ?? null) !== $input['mutation_owner']) {
                throw new ControlRefusal('incomplete mutation acquire does not match current fence intent');
            }
        }
        if ($this->publisher !== null) {
            $target = self::controlTarget($current, $mutation);
            $this->publisherCall(
                'hold',
                fn (): null => $this->publishHold($tenantId, $siteId, $operationId, $target)
            );
        }
        $mutation['state'] = 'held';
        $current['mutation'] = $mutation;
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        $result = self::mutationResult($current, $mutation, 'held');
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function mutationRead(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentPresent($state, $tenantId, $siteId);
        unset($siteKey, $site);
        self::assertIdentityInput($input, $current);
        $mutation = self::requireMutation($current, $input, ['held', 'released']);
        if ($receipt === null) {
            self::reserveOperation(
                $state,
                $receiptKey,
                'mutation-read',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
        } else {
            self::assertReceiptIdentity($receipt, $current);
        }
        $result = self::mutationResult($current, $mutation, (string) $mutation['state']);
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function mutationRelease(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentPresent($state, $tenantId, $siteId);
        self::assertIdentityInput($input, $current);
        if ($receipt === null) {
            self::assertNoForeignExecutingOperation($state, $current, $receiptKey);
            $mutation = self::requireMutation($current, $input, ['held']);
            $mutation['state'] = 'releasing';
            $current['mutation'] = $mutation;
            $site['current'] = $current;
            $state['sites'][$siteKey] = $site;
            self::reserveOperation(
                $state,
                $receiptKey,
                'mutation-release',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
            $session->save($state);
        } else {
            self::assertReceiptIdentity($receipt, $current);
            $mutation = $current['mutation'] ?? null;
            if (!is_array($mutation)
                || ($mutation['state'] ?? null) !== 'releasing'
                || ($mutation['operation_id'] ?? null) !== $operationId
                || !self::mutationInputMatches($input, $mutation)) {
                throw new ControlRefusal('incomplete mutation release does not match current fence intent');
            }
        }
        if ($this->publisher !== null) {
            $target = self::controlTarget($current, $mutation);
            $this->publisherCall(
                'release',
                fn (): null => $this->publishRelease(
                    $tenantId,
                    $siteId,
                    (string) $mutation['operation_id'],
                    $target
                )
            );
        }
        $releasedReceipt = self::hashCanonical('duo-cloud-preview-released-mutation/v1', [
            'held_receipt_sha256' => $mutation['held_receipt_sha256'],
            'mutation_generation' => $mutation['generation'],
            'mutation_id' => $mutation['id'],
            'mutation_owner' => $mutation['owner'],
            'operation_id' => $mutation['operation_id'],
            'resource_id' => $current['resource_id'],
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ]);
        $mutation['receipt_sha256'] = $releasedReceipt;
        $mutation['state'] = 'released';
        $current['mutation'] = $mutation;
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        $result = self::mutationResult($current, $mutation, 'released');
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function mutateWorkload(
        AuthorityStateSession $session,
        array $state,
        string $action,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentPresent($state, $tenantId, $siteId);
        self::assertIdentityInput($input, $current);
        $mutation = self::requireMutation($current, $input, ['held']);
        if ($receipt === null) {
            self::assertNoForeignExecutingOperation($state, $current, $receiptKey);
            self::reserveOperation(
                $state,
                $receiptKey,
                $action,
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
            $session->save($state);
        } else {
            self::assertReceiptIdentity($receipt, $current);
        }
        $authority = self::runtimeAuthority($current, $mutation, $tenantId, $siteId, $operationId);
        $repositorySync = null;
        if ($action === 'snapshot-restore') {
            $runtimeResult = $this->runtimeCall(
                'snapshot restore',
                fn (): array => $this->runtime->restoreSnapshot($authority, [
                    'database_sha256' => $input['database_sha256'],
                    'media_sha256' => $input['media_sha256'],
                    'snapshot_set_id' => $input['snapshot_set_id'],
                ])
            );
        } elseif ($action === 'repository-sync') {
            if (!$this->runtime instanceof RepositorySyncRuntime) {
                throw new ControlRefusal('preview workload runtime cannot synchronize a configured repository');
            }
            $runtimeResult = $this->runtimeCall(
                'repository synchronization',
                fn (): array => $this->runtime->syncRepository($authority, [
                    'branch_commit' => $input['branch_commit'],
                    'branch_ref' => $input['branch_ref'],
                    'candidate_publication_receipt_sha256' =>
                        $input['candidate_publication_receipt_sha256'],
                    'repository_authority_sha256' => $input['repository_authority_sha256'],
                ])
            );
        } elseif ($action === 'repository-materialize') {
            $repositorySync = self::completedRepositorySync(
                $state,
                $tenantId,
                $siteId,
                $operationId,
                $current,
                $input
            );
            $runtimeResult = $this->runtimeCall(
                'repository materialization',
                fn (): array => $this->runtime->materializeRepository($authority, [
                    'branch_commit' => $input['branch_commit'],
                    'branch_ref' => $input['branch_ref'],
                    'repo_path' => $input['repo_path'],
                ])
            );
        } else {
            if ($input['url'] !== $current['url']) {
                throw new ControlRefusal('URL mutation differs from the generation-owned public URL');
            }
            $runtimeResult = $this->runtimeCall(
                'URL configuration',
                fn (): array => $this->runtime->configureUrl($authority, $input['url'])
            );
        }
        self::stageEvidence($runtimeResult, "runtime $action");
        $current['evidence'][$receiptKey] = [
            'action' => $action,
            'evidence_sha256' => $runtimeResult['evidence_sha256'],
            'operation_id' => $operationId,
            'request_sha256' => $requestHash,
        ];
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        $result = self::identityResult($current);
        if ($action === 'snapshot-restore') {
            $result['snapshot_set_id'] = $input['snapshot_set_id'];
        } elseif ($action === 'repository-sync') {
            $result['branch_commit'] = $input['branch_commit'];
            $result['branch_ref'] = $input['branch_ref'];
            $result['candidate_publication_receipt_sha256'] =
                $input['candidate_publication_receipt_sha256'];
            $result['repository_authority_sha256'] = $input['repository_authority_sha256'];
            $result['repository_sync_receipt_sha256'] = self::hashCanonical(
                'duo-cloud-preview-repository-sync-receipt/v1',
                [
                    'authority' => $authority,
                    'evidence_sha256' => $runtimeResult['evidence_sha256'],
                    'repository' => [
                        'branch_commit' => $input['branch_commit'],
                        'branch_ref' => $input['branch_ref'],
                        'candidate_publication_receipt_sha256' =>
                            $input['candidate_publication_receipt_sha256'],
                        'repository_authority_sha256' => $input['repository_authority_sha256'],
                    ],
                ]
            );
        } elseif ($action === 'repository-materialize') {
            if (!is_array($repositorySync)) {
                throw new ControlRefusal('repository materialization lost its completed sync authority');
            }
            $result['branch_commit'] = $input['branch_commit'];
            $result['repository_receipt_sha256'] = self::hashCanonical(
                'duo-cloud-preview-repository-receipt/v1',
                [
                    'authority' => $authority,
                    'evidence_sha256' => $runtimeResult['evidence_sha256'],
                    'repository' => [
                        'branch_commit' => $input['branch_commit'],
                        'branch_ref' => $input['branch_ref'],
                        'repo_path' => $input['repo_path'],
                        'repository_authority_sha256' =>
                            $input['expected_repository_authority_sha256'],
                        'repository_sync_receipt_sha256' =>
                            $repositorySync['repository_sync_receipt_sha256'],
                    ],
                ]
            );
        }
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function sleep(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentInStates(
            $state,
            $tenantId,
            $siteId,
            $receipt === null ? ['present'] : ['sleeping'],
            'sleep'
        );
        self::assertIdentityInput($input, $current);
        $mutation = self::requireMutation($current, $input, ['held']);
        if ($receipt === null) {
            self::assertNoForeignExecutingOperation($state, $current, $receiptKey);
            $current['sleep'] = [
                'action' => 'sleep',
                'execution_evidence_sha256' => null,
                'operation_id' => $operationId,
                'request_sha256' => $requestHash,
                'routing_evidence_sha256' => null,
                'state' => 'intent',
            ];
            $current['state'] = 'sleeping';
            $site['current'] = $current;
            $state['sites'][$siteKey] = $site;
            self::reserveOperation(
                $state,
                $receiptKey,
                'sleep',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
            // Route removal is the first externally observable boundary. Its
            // intent is durable before either runtime stage can take effect.
            $session->save($state);
        } else {
            self::assertReceiptIdentity($receipt, $current);
            $sleep = $current['sleep'] ?? null;
            if (!is_array($sleep)
                || ($sleep['action'] ?? null) !== 'sleep'
                || ($sleep['operation_id'] ?? null) !== $operationId
                || ($sleep['request_sha256'] ?? null) !== $requestHash) {
                throw new ControlRefusal('incomplete sleep does not match current transition intent');
            }
        }

        $sleep = $current['sleep'];
        $authority = self::runtimeAuthority($current, $mutation, $tenantId, $siteId, $operationId);
        if ($sleep['state'] === 'intent') {
            $runtimeResult = $this->runtimeCall(
                'sleep routing revocation',
                fn (): array => $this->runtime->revokeRouting($authority)
            );
            self::stageEvidence($runtimeResult, 'sleep routing revocation');
            $sleep['routing_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $sleep['state'] = 'routing-revoked';
            self::saveSleepPhase($session, $state, $siteKey, $site, $current, $sleep);
        }
        if ($sleep['state'] === 'routing-revoked') {
            $runtimeResult = $this->runtimeCall(
                'sleep execution revocation',
                fn (): array => $this->runtime->revokeExecution($authority)
            );
            self::stageEvidence($runtimeResult, 'sleep execution revocation');
            $sleep['execution_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $sleep['state'] = 'asleep';
        }
        if ($sleep['state'] !== 'asleep') {
            throw new ControlRefusal('preview slot sleep has an invalid durable phase');
        }
        $current['sleep'] = $sleep;
        $current['state'] = 'asleep';
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        return self::completeOperation(
            $session,
            $state,
            $receiptKey,
            self::identityResult($current) + ['sleep_state' => 'asleep']
        );
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function wake(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentInStates(
            $state,
            $tenantId,
            $siteId,
            $receipt === null ? ['asleep'] : ['waking'],
            'wake'
        );
        self::assertIdentityInput($input, $current);
        $mutation = self::requireMutation($current, $input, ['held']);
        if ($receipt === null) {
            self::assertNoForeignExecutingOperation($state, $current, $receiptKey);
            $current['sleep'] = [
                'action' => 'wake',
                'execution_evidence_sha256' => null,
                'operation_id' => $operationId,
                'request_sha256' => $requestHash,
                'routing_evidence_sha256' => null,
                'state' => 'intent',
            ];
            $current['state'] = 'waking';
            $site['current'] = $current;
            $state['sites'][$siteKey] = $site;
            self::reserveOperation(
                $state,
                $receiptKey,
                'wake',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
            // Execution must read back under the retained generation before
            // its public URL can become routable again.
            $session->save($state);
        } else {
            self::assertReceiptIdentity($receipt, $current);
            $sleep = $current['sleep'] ?? null;
            if (!is_array($sleep)
                || ($sleep['action'] ?? null) !== 'wake'
                || ($sleep['operation_id'] ?? null) !== $operationId
                || ($sleep['request_sha256'] ?? null) !== $requestHash) {
                throw new ControlRefusal('incomplete wake does not match current transition intent');
            }
        }

        $sleep = $current['sleep'];
        $authority = self::runtimeAuthority($current, $mutation, $tenantId, $siteId, $operationId);
        if ($sleep['state'] === 'intent') {
            $runtimeResult = $this->runtimeCall(
                'wake execution resume',
                fn (): array => $this->runtime->resumeExecution($authority)
            );
            self::stageEvidence($runtimeResult, 'wake execution resume');
            $sleep['execution_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $sleep['state'] = 'execution-resumed';
            self::saveSleepPhase($session, $state, $siteKey, $site, $current, $sleep);
        }
        if ($sleep['state'] === 'execution-resumed') {
            $runtimeResult = $this->runtimeCall(
                'wake URL routing restore',
                fn (): array => $this->runtime->configureUrl($authority, (string) $current['url'])
            );
            self::stageEvidence($runtimeResult, 'wake URL routing restore');
            $sleep['routing_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $sleep['state'] = 'routing-restored';
            self::saveSleepPhase($session, $state, $siteKey, $site, $current, $sleep);
        }
        if ($sleep['state'] !== 'routing-restored') {
            throw new ControlRefusal('preview slot wake has an invalid durable phase');
        }
        $observed = $this->runtimeCall(
            'post-wake inspection',
            fn (): array => $this->runtime->inspect(
                self::runtimeLease(self::identityFromRecord($current), $tenantId, $siteId, $operationId)
            )
        );
        self::inspectionEvidence($observed);
        if ($observed['presence'] !== 'present' || $observed['url'] !== $current['url']) {
            throw new ControlRefusal('woken preview slot did not read back as the exact present URL');
        }
        $current['sleep'] = null;
        $current['state'] = 'present';
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        return self::completeOperation(
            $session,
            $state,
            $receiptKey,
            self::identityResult($current) + ['sleep_state' => 'awake']
        );
    }

    /**
     * repository-materialize is authorized only by the exact completed sync
     * receipt for this lifecycle operation. The remote ref may already have
     * been deleted by the controller; the runtime consumes only its private
     * fetched ref after this durable comparison.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $current
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function completedRepositorySync(
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $current,
        array $input
    ): array {
        $key = self::receiptKey($tenantId, $siteId, 'repository-sync', $operationId);
        $receipt = $state['operations'][$key] ?? null;
        if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'complete') {
            throw new ControlRefusal('repository materialization has no durable completed sync receipt');
        }
        $sync = self::cachedResult($receipt);
        self::exactKeys($sync, array_merge(array_keys(self::identityResult($current)), [
            'branch_commit', 'branch_ref', 'candidate_publication_receipt_sha256',
            'repository_authority_sha256', 'repository_sync_receipt_sha256',
        ]), 'completed repository sync result');
        foreach (self::identityResult($current) as $field => $expected) {
            if (($sync[$field] ?? null) !== $expected) {
                throw new ControlRefusal('completed repository sync receipt is stale or foreign');
            }
        }
        foreach ([
            'branch_commit' => 'branch_commit',
            'branch_ref' => 'branch_ref',
            'repository_authority_sha256' => 'expected_repository_authority_sha256',
            'repository_sync_receipt_sha256' => 'expected_repository_sync_receipt_sha256',
        ] as $resultField => $inputField) {
            if (($sync[$resultField] ?? null) !== ($input[$inputField] ?? null)) {
                throw new ControlRefusal('repository materialization differs from its completed sync receipt');
            }
        }
        self::sha256(
            $sync['candidate_publication_receipt_sha256'] ?? null,
            'completed candidate publication receipt'
        );
        return $sync;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function ttlSet(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        if ($receipt !== null) {
            throw new ControlRefusal('executing TTL set receipt cannot exist without its atomic TTL evidence');
        }
        [$siteKey, $site, $current] = self::currentPresent($state, $tenantId, $siteId);
        self::assertIdentityInput($input, $current);
        self::requireMutation($current, $input, ['held']);
        self::assertNoForeignExecutingOperation($state, $current, $receiptKey);
        $lastGeneration = $current['last_ttl_generation'] ?? null;
        if (!is_int($lastGeneration) || $lastGeneration < 0 || $lastGeneration === PHP_INT_MAX) {
            throw new ControlRefusal('preview TTL generation is invalid or exhausted');
        }
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - $input['ttl_seconds']) {
            throw new ControlRefusal('preview TTL clock is invalid or exhausted');
        }
        $generation = $lastGeneration + 1;
        $ttl = self::newTtl(
            $current,
            $tenantId,
            $siteId,
            $operationId,
            $generation,
            $now + $input['ttl_seconds'],
            'active'
        );
        $current['last_ttl_generation'] = $generation;
        $current['ttl'] = $ttl;
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        self::reserveOperation(
            $state,
            $receiptKey,
            'ttl-set',
            $tenantId,
            $siteId,
            $operationId,
            $requestHash,
            $current
        );
        return self::completeOperation(
            $session,
            $state,
            $receiptKey,
            self::ttlResult($current, $ttl)
        );
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function ttlRead(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt
    ): array {
        [$siteKey, $site, $current] = self::currentPresent($state, $tenantId, $siteId);
        unset($siteKey, $site);
        self::assertIdentityInput($input, $current);
        self::requireMutation($current, $input, ['held']);
        $ttl = $current['ttl'] ?? null;
        if (!is_array($ttl)
            || ($ttl['expires_at'] ?? null) !== $input['expected_expires_at']
            || ($ttl['generation'] ?? null) !== $input['expected_ttl_generation']
            || ($ttl['lease_id'] ?? null) !== $input['expected_ttl_lease_id']
            || ($ttl['receipt_sha256'] ?? null) !== $input['expected_ttl_receipt_sha256']
            || ($ttl['state'] ?? null) !== 'active') {
            throw new ControlRefusal('TTL read does not match the exact active TTL evidence');
        }
        if ($receipt === null) {
            self::reserveOperation(
                $state,
                $receiptKey,
                'ttl-read',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
        } else {
            self::assertReceiptIdentity($receipt, $current);
        }
        // Expiry is observable metadata only. Reading it never changes presence,
        // routing, execution, or state; destroy remains a separate exact reap.
        return self::completeOperation($session, $state, $receiptKey, self::ttlResult($current, $ttl));
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $input @param ?array<string,mixed> $receipt */
    private function destroy(
        AuthorityStateSession $session,
        array $state,
        string $tenantId,
        string $siteId,
        string $operationId,
        array $input,
        string $requestHash,
        string $receiptKey,
        ?array $receipt,
        bool $expiredService = false
    ): array {
        $siteKey = self::siteKey($tenantId, $siteId);
        $site = $state['sites'][$siteKey] ?? null;
        $current = is_array($site) ? ($site['current'] ?? null) : null;
        if ($receipt === null) {
            $terminal = is_array($site) ? ($site['terminal_absence'] ?? null) : null;
            if (is_array($site) && $current === null && is_array($terminal)) {
                // A service TTL reap can win after the controller durably
                // chose this exact generation/fence. Converging its already
                // terminal identity performs no physical action and prevents
                // a stale sleep-reap retry from becoming unrecoverable.
                self::assertIdentityInput($input, $terminal);
                self::reserveOperation(
                    $state,
                    $receiptKey,
                    'destroy',
                    $tenantId,
                    $siteId,
                    $operationId,
                    $requestHash,
                    $terminal
                );
                return self::completeOperation($session, $state, $receiptKey, [
                    'absence_proof_sha256' => $terminal['absence_proof_sha256'],
                    'disposition' => 'destroyed',
                    'environment_identity' => $terminal['environment_identity'],
                    'lease_generation' => $terminal['lease_generation'],
                    'lease_id' => $terminal['lease_id'],
                    'ownership_receipt_sha256' => $terminal['ownership_receipt_sha256'],
                    'resource_id' => $terminal['resource_id'],
                ]);
            }
            if (!is_array($site) || !is_array($current)
                || !in_array($current['state'] ?? null, $expiredService
                    ? ['acquiring', 'asleep', 'present', 'sleeping', 'waking']
                    : ['asleep', 'present', 'sleeping', 'waking'], true)) {
                throw new ControlRefusal('preview slot has no exact retained generation to destroy');
            }
            self::assertIdentityInput($input, $current);
            if ($expiredService && $current['state'] === 'acquiring') {
                $current = $this->completeExpiredAcquisition(
                    $session,
                    $state,
                    $siteKey,
                    $site,
                    $current,
                    $tenantId,
                    $siteId
                );
            }
            self::supersedeGenerationOperations(
                $state,
                $current,
                $tenantId,
                $siteId,
                $operationId,
                $requestHash
            );
            if ($expiredService) {
                [$mutation, $controlAction] = self::expiredMutation(
                    $current,
                    $tenantId,
                    $siteId,
                    $operationId
                );
                $current['last_mutation_generation'] = max(
                    (int) $current['last_mutation_generation'],
                    (int) $mutation['generation']
                );
            } else {
                $mutation = self::requireMutation($current, $input, ['held']);
                $controlAction = 'release';
            }
            $current['state'] = 'reaping';
            $current['sleep'] = null;
            $mutation['state'] = 'reaping';
            $mutation['receipt_sha256'] = $mutation['held_receipt_sha256'];
            $current['mutation'] = $mutation;
            $controlReleased = $controlAction === 'none';
            $current['reap'] = [
                'control_action' => $controlAction,
                'control_released' => $controlReleased,
                'delete_evidence_sha256' => null,
                'execution_evidence_sha256' => null,
                'operation_id' => $operationId,
                'request_sha256' => $requestHash,
                'routing_evidence_sha256' => null,
                'state' => $controlReleased ? 'control-released' : 'intent',
            ];
            $site['current'] = $current;
            $state['sites'][$siteKey] = $site;
            self::reserveOperation(
                $state,
                $receiptKey,
                'destroy',
                $tenantId,
                $siteId,
                $operationId,
                $requestHash,
                $current
            );
            // This is the revocation boundary: every later lifecycle action now
            // refuses before runtime access, even if the first reap stage fails.
            $session->save($state);
        } else {
            if (!is_array($site)
                || !is_array($current)
                || ($current['state'] ?? null) !== 'reaping'
                || !is_array($current['reap'] ?? null)
                || ($current['reap']['operation_id'] ?? null) !== $operationId
                || ($current['reap']['request_sha256'] ?? null) !== $requestHash) {
                throw new ControlRefusal('incomplete destroy does not match current reap intent');
            }
            self::assertReceiptIdentity($receipt, $current);
            self::assertIdentityInput($input, $current);
            $mutation = $current['mutation'] ?? null;
            if (!is_array($mutation)
                || !$expiredService && !self::mutationInputMatches($input, $mutation)) {
                throw new ControlRefusal('incomplete destroy mutation authority changed');
            }
        }

        $reap = $current['reap'];
        if ($reap['state'] === 'intent') {
            if ($reap['control_action'] === 'hold-release' && $this->publisher !== null) {
                $target = self::controlTarget($current, $mutation);
                $this->publisherCall(
                    'reap hold reconciliation',
                    fn (): null => $this->publishHold(
                        $tenantId,
                        $siteId,
                        (string) $mutation['operation_id'],
                        $target
                    )
                );
            }
            if ($reap['control_action'] === 'hold-release') {
                // A lost release response must retry release, never try to
                // reacquire an authority whose release may already be durable.
                $reap['state'] = 'control-held';
                self::saveReapPhase($session, $state, $siteKey, $site, $current, $reap);
            }
        }
        if (in_array($reap['state'], ['control-held', 'intent'], true)) {
            if ($this->publisher !== null) {
                $target = self::controlTarget($current, $mutation);
                $this->publisherCall(
                    'reap release',
                    fn (): null => $this->publishRelease(
                        $tenantId,
                        $siteId,
                        (string) $mutation['operation_id'],
                        $target
                    )
                );
            }
            $reap['control_released'] = true;
            $reap['state'] = 'control-released';
            self::saveReapPhase($session, $state, $siteKey, $site, $current, $reap);
        }
        $authority = self::runtimeAuthority($current, $mutation, $tenantId, $siteId, $operationId);
        if ($reap['state'] === 'control-released') {
            $runtimeResult = $this->runtimeCall(
                'routing revocation',
                fn (): array => $this->runtime->revokeRouting($authority)
            );
            self::stageEvidence($runtimeResult, 'routing revocation');
            $reap['routing_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $reap['state'] = 'routing-revoked';
            self::saveReapPhase($session, $state, $siteKey, $site, $current, $reap);
        }
        if ($reap['state'] === 'routing-revoked') {
            $runtimeResult = $this->runtimeCall(
                'execution revocation',
                fn (): array => $this->runtime->revokeExecution($authority)
            );
            self::stageEvidence($runtimeResult, 'execution revocation');
            $reap['execution_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $reap['state'] = 'execution-revoked';
            self::saveReapPhase($session, $state, $siteKey, $site, $current, $reap);
        }
        if ($reap['state'] === 'execution-revoked') {
            $runtimeResult = $this->runtimeCall(
                'generation state deletion',
                fn (): array => $this->runtime->deleteState($authority)
            );
            self::stageEvidence($runtimeResult, 'generation state deletion');
            $reap['delete_evidence_sha256'] = $runtimeResult['evidence_sha256'];
            $reap['state'] = 'state-deleted';
            self::saveReapPhase($session, $state, $siteKey, $site, $current, $reap);
        }
        if ($reap['state'] !== 'state-deleted') {
            throw new ControlRefusal('preview slot reap has an invalid durable phase');
        }
        $absence = $this->runtimeCall(
            'terminal absence verification',
            fn (): array => $this->runtime->verifyAbsent(
                self::runtimeLease(self::identityFromRecord($current), $tenantId, $siteId, $operationId)
            )
        );
        self::absenceEvidence($absence);
        if ($absence['absent'] !== true) {
            throw new ControlRefusal('preview slot state was deleted but terminal absence was not verified');
        }
        $identity = self::identityFromRecord($current);
        $site['current'] = null;
        $site['terminal_absence'] = $identity + [
            'absence_proof_sha256' => $absence['absence_proof_sha256'],
            'url' => $current['url'],
        ];
        $state['sites'][$siteKey] = $site;
        $result = [
            'absence_proof_sha256' => $absence['absence_proof_sha256'],
            'disposition' => 'destroyed',
            'environment_identity' => $identity['environment_identity'],
            'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'],
            'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ];
        return self::completeOperation($session, $state, $receiptKey, $result);
    }

    /**
     * An indeterminate create may have any prefix of runtime provision on disk.
     * Exact provision replay is the runtime's recovery contract; completing it
     * before teardown gives every physical prefix the same ordered reap path.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $site
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function completeExpiredAcquisition(
        AuthorityStateSession $session,
        array &$state,
        string $siteKey,
        array &$site,
        array $current,
        string $tenantId,
        string $siteId
    ): array {
        $acquisitionOperation = (string) $current['acquisition_operation_id'];
        $lease = self::runtimeLease(
            self::identityFromRecord($current),
            $tenantId,
            $siteId,
            $acquisitionOperation
        );
        $provision = $this->runtimeCall(
            'expired acquisition provision reconciliation',
            fn (): array => $this->runtime->provision($lease)
        );
        self::provisionEvidence($provision);
        $observed = $this->runtimeCall(
            'expired acquisition inspection',
            fn (): array => $this->runtime->inspect($lease)
        );
        self::inspectionEvidence($observed);
        if ($observed['presence'] !== 'present' || $observed['url'] !== $provision['url']) {
            throw new ControlRefusal('expired acquisition did not reconcile to the exact present URL');
        }
        $current['create_evidence_sha256'] = self::hashCanonical(
            'duo-cloud-preview-create-evidence/v1',
            ['inspection' => $observed, 'provision' => $provision]
        );
        $current['state'] = 'present';
        $current['url'] = $observed['url'];
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        // If teardown crashes next, the following sweep starts from a durable
        // present generation carrying the same original provisional deadline.
        $session->save($state);
        return $current;
    }

    /**
     * @param array<string,mixed> $current
     * @return array{0:array<string,mixed>,1:string}
     */
    private static function expiredMutation(
        array $current,
        string $tenantId,
        string $siteId,
        string $operationId
    ): array {
        $mutation = $current['mutation'] ?? null;
        if ($mutation === null) {
            $lastGeneration = $current['last_mutation_generation'] ?? null;
            if (!is_int($lastGeneration) || $lastGeneration < 0 || $lastGeneration === PHP_INT_MAX) {
                throw new ControlRefusal('preview mutation generation is invalid or exhausted');
            }
            $generation = $lastGeneration + 1;
            $owner = 'duo-env-reap-' . $operationId;
            $mutationId = 'cloud-mutation-' . hash(
                'sha256',
                "duo-cloud-preview-mutation/v1\0$tenantId\0$siteId\0{$current['resource_id']}\0"
                . "{$current['lease_generation']}\0$generation\0$operationId"
            );
            $heldReceipt = self::hashCanonical('duo-cloud-preview-held-mutation/v1', [
                'environment_identity' => $current['environment_identity'],
                'lease_generation' => $current['lease_generation'],
                'lease_id' => $current['lease_id'],
                'mutation_generation' => $generation,
                'mutation_id' => $mutationId,
                'mutation_owner' => $owner,
                'operation_id' => $operationId,
                'ownership_receipt_sha256' => $current['ownership_receipt_sha256'],
                'resource_id' => $current['resource_id'],
                'site_id' => $siteId,
                'tenant_id' => $tenantId,
            ]);
            return [[
                'generation' => $generation,
                'held_receipt_sha256' => $heldReceipt,
                'id' => $mutationId,
                'operation_id' => $operationId,
                'owner' => $owner,
                'receipt_sha256' => $heldReceipt,
                'state' => 'held',
            ], 'none'];
        }
        if (!is_array($mutation)) {
            throw new ControlRefusal('expired preview mutation authority is malformed');
        }
        $controlAction = match ($mutation['state'] ?? null) {
            'publishing' => 'hold-release',
            'held', 'releasing' => 'release',
            'released' => 'none',
            default => throw new ControlRefusal('expired preview mutation authority cannot be reaped'),
        };
        return [$mutation, $controlAction];
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $site @param array<string,mixed> $current @param array<string,mixed> $reap */
    private static function saveReapPhase(
        AuthorityStateSession $session,
        array &$state,
        string $siteKey,
        array &$site,
        array &$current,
        array $reap
    ): void {
        $current['reap'] = $reap;
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        $session->save($state);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $site @param array<string,mixed> $current @param array<string,mixed> $sleep */
    private static function saveSleepPhase(
        AuthorityStateSession $session,
        array &$state,
        string $siteKey,
        array &$site,
        array &$current,
        array $sleep
    ): void {
        $current['sleep'] = $sleep;
        $site['current'] = $current;
        $state['sites'][$siteKey] = $site;
        $session->save($state);
    }

    /** @param callable():array<string,mixed> $call @return array<string,mixed> */
    private function runtimeCall(string $stage, callable $call): array {
        try {
            $result = $call();
        } catch (\Throwable $error) {
            throw new ControlRefusal(
                "workload runtime $stage outcome is indeterminate; retry the exact lifecycle request",
                0,
                $error
            );
        }
        if (array_is_list($result) && $result !== []) {
            throw new ControlRefusal("workload runtime $stage evidence must be a canonical object");
        }
        CanonicalJson::encode($result);
        return $result;
    }

    /** @param callable():null $call */
    private function publisherCall(string $stage, callable $call): void {
        try {
            $call();
        } catch (\Throwable $error) {
            throw new ControlRefusal(
                "command authority $stage outcome is indeterminate; retry the exact lifecycle request",
                0,
                $error
            );
        }
    }

    /** @param array<string,mixed> $target */
    private function publishHold(string $tenantId, string $siteId, string $operationId, array $target): null {
        $this->publisher?->hold($tenantId, $siteId, $operationId, $target);
        return null;
    }

    /** @param array<string,mixed> $target */
    private function publishRelease(string $tenantId, string $siteId, string $operationId, array $target): null {
        $this->publisher?->release($tenantId, $siteId, $operationId, $target);
        return null;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $identity */
    private static function reserveOperation(
        array &$state,
        string $receiptKey,
        string $action,
        string $tenantId,
        string $siteId,
        string $operationId,
        string $requestHash,
        array $identity
    ): void {
        if (isset($state['operations'][$receiptKey])) {
            throw new ControlRefusal('lifecycle operation receipt already exists');
        }
        self::assertRegularOperationCapacity(
            $state['operations'],
            $tenantId,
            $siteId,
            (int) $identity['lease_generation'],
            $action
        );
        $state['operations'][$receiptKey] = [
            'action' => $action,
            'environment_identity' => $identity['environment_identity'],
            'lease_generation' => $identity['lease_generation'],
            'operation_id' => $operationId,
            'request_sha256' => $requestHash,
            'resource_id' => $identity['resource_id'],
            'site_id' => $siteId,
            'status' => 'executing',
            'tenant_id' => $tenantId,
        ];
        self::assertOperationDispatchCapacity($state['operations'], $receiptKey);
    }

    /** @param array<string,mixed> $operations */
    private static function assertRegularOperationCapacity(
        array $operations,
        string $tenantId,
        string $siteId,
        int $leaseGeneration,
        string $action
    ): void {
        if (in_array($action, ['destroy', 'mutation-acquire', 'mutation-release'], true)) {
            return;
        }
        $count = 0;
        foreach ($operations as $receipt) {
            if (is_array($receipt)
                && ($receipt['tenant_id'] ?? null) === $tenantId
                && ($receipt['site_id'] ?? null) === $siteId
                && ($receipt['lease_generation'] ?? null) === $leaseGeneration) {
                $count++;
            }
        }
        if ($count >= self::REGULAR_OPERATIONS_PER_GENERATION_LIMIT) {
            throw new ControlRefusal(
                'preview lifecycle operation count reached its cleanup-reserved per-generation limit'
            );
        }
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $result @return array<string,mixed> */
    private static function completeOperation(
        AuthorityStateSession $session,
        array $state,
        string $receiptKey,
        array $result
    ): array {
        $receipt = $state['operations'][$receiptKey] ?? null;
        if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'executing') {
            throw new ControlRefusal('lifecycle operation cannot publish without its durable intent');
        }
        $bytes = CanonicalJson::encode($result) . "\n";
        if (strlen($bytes) > self::RESULT_BYTES_LIMIT) {
            throw new ControlRefusal('lifecycle result exceeds its closed evidence byte limit');
        }
        $receipt['result_base64'] = base64_encode($bytes);
        $receipt['result_sha256'] = hash('sha256', $bytes);
        $receipt['status'] = 'complete';
        $state['operations'][$receiptKey] = $receipt;
        self::compactOperations($state);
        self::assertOperationBounds($state['operations']);
        $session->save($state);
        // Return the same canonical key order that an exact replay decodes.
        // PHP array order is observable to strict callers even though JSON
        // object order is not, so first response and cached response must agree.
        return CanonicalJson::decodeObject($bytes, self::RESULT_BYTES_LIMIT);
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private static function cachedResult(array $receipt): array {
        $encoded = $receipt['result_base64'] ?? null;
        $expected = $receipt['result_sha256'] ?? null;
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($bytes)
            || !is_string($expected)
            || !hash_equals($expected, hash('sha256', $bytes))) {
            throw new ControlRefusal('cached lifecycle evidence is corrupt');
        }
        return CanonicalJson::decodeObject($bytes, self::RESULT_BYTES_LIMIT);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $current */
    private static function assertNoForeignExecutingOperation(
        array $state,
        array $current,
        string $allowedReceiptKey
    ): void {
        foreach ($state['operations'] as $key => $receipt) {
            if ($key === $allowedReceiptKey || !is_array($receipt) || ($receipt['status'] ?? null) !== 'executing') {
                continue;
            }
            if (($receipt['resource_id'] ?? null) === $current['resource_id']
                && ($receipt['lease_generation'] ?? null) === $current['lease_generation']
                && ($receipt['action'] ?? null) !== 'inspect') {
                throw new ControlRefusal('preview generation has another indeterminate lifecycle operation');
            }
        }
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $current */
    private static function supersedeGenerationOperations(
        array &$state,
        array $current,
        string $tenantId,
        string $siteId,
        string $destroyOperationId,
        string $destroyRequestHash
    ): void {
        foreach ($state['operations'] as $key => $receipt) {
            if (!is_array($receipt)
                || ($receipt['status'] ?? null) !== 'executing'
                || ($receipt['tenant_id'] ?? null) !== $tenantId
                || ($receipt['site_id'] ?? null) !== $siteId
                || ($receipt['resource_id'] ?? null) !== $current['resource_id']
                || ($receipt['lease_generation'] ?? null) !== $current['lease_generation']) {
                continue;
            }
            if (($receipt['action'] ?? null) === 'inspect') {
                // Exact inspect is the one operation that can resume against
                // terminal_absence and prove the janitor's winning result.
                continue;
            }
            if (($receipt['action'] ?? null) === 'destroy') {
                throw new ControlRefusal('preview generation already has an indeterminate destroy');
            }
            self::assertReceiptIdentity($receipt, $current);
            $receipt['status'] = 'superseded';
            $receipt['superseded_by_operation_id'] = $destroyOperationId;
            $receipt['superseded_by_request_sha256'] = $destroyRequestHash;
            $state['operations'][$key] = $receipt;
        }
    }

    /** @param array<string,mixed> $state */
    private static function assertNoSiteExecutingOperation(
        array $state,
        string $tenantId,
        string $siteId
    ): void {
        foreach ($state['operations'] as $receipt) {
            if (is_array($receipt)
                && ($receipt['tenant_id'] ?? null) === $tenantId
                && ($receipt['site_id'] ?? null) === $siteId
                && ($receipt['status'] ?? null) === 'executing') {
                throw new ControlRefusal(
                    'preview site has an indeterminate lifecycle operation that must be replayed exactly'
                );
            }
        }
    }

    /** @param array<string,mixed> $state */
    private static function compactOperations(array &$state): bool {
        $changed = false;
        foreach ($state['operations'] as $receiptKey => $candidate) {
            if (!is_array($candidate)) {
                throw new ControlRefusal('preview lifecycle operation map is invalid');
            }
            if (($candidate['status'] ?? null) === 'executing') {
                continue;
            }
            $tenantId = (string) ($candidate['tenant_id'] ?? '');
            $siteId = (string) ($candidate['site_id'] ?? '');
            $site = $state['sites'][self::siteKey($tenantId, $siteId)] ?? null;
            if (!is_array($site)) {
                continue;
            }
            $generation = $candidate['lease_generation'] ?? null;
            $current = $site['current'] ?? null;
            $terminal = $site['terminal_absence'] ?? null;
            $currentGeneration = is_array($current) ? ($current['lease_generation'] ?? null) : null;
            $terminalGeneration = is_array($terminal) ? ($terminal['lease_generation'] ?? null) : null;
            if ($generation !== $currentGeneration && $generation !== $terminalGeneration) {
                unset($state['operations'][$receiptKey]);
                $changed = true;
            }
        }
        self::assertOperationBounds($state['operations']);
        return $changed;
    }

    /** @param array<string,mixed> $operations */
    private static function assertOperationBounds(array $operations): void {
        $groups = [];
        foreach ($operations as $receiptKey => $candidate) {
            if (!is_array($candidate)) {
                throw new ControlRefusal('preview lifecycle operation map is invalid');
            }
            $group = ($candidate['tenant_id'] ?? '') . "\0" . ($candidate['site_id'] ?? '') . "\0"
                . ($candidate['lease_generation'] ?? '');
            $groups[$group]['count'] = ($groups[$group]['count'] ?? 0) + 1;
            $groups[$group]['bytes'] = ($groups[$group]['bytes'] ?? 0)
                + strlen((string) $receiptKey) + strlen(CanonicalJson::encode($candidate));
        }
        foreach ($groups as $group) {
            if ($group['count'] > self::OPERATIONS_PER_GENERATION_LIMIT) {
                throw new ControlRefusal('preview lifecycle operation count exceeds its per-generation limit');
            }
            if ($group['bytes'] > self::OPERATIONS_PER_GENERATION_BYTES_LIMIT) {
                throw new ControlRefusal('preview lifecycle operation bytes exceed its per-generation limit');
            }
        }
    }

    /** @param array<string,mixed> $operations */
    private static function assertOperationDispatchCapacity(array $operations, string $receiptKey): void {
        self::assertOperationBounds($operations);
        $reserved = $operations[$receiptKey] ?? null;
        if (!is_array($reserved) || ($reserved['status'] ?? null) !== 'executing') {
            throw new ControlRefusal('lifecycle operation reservation is corrupt');
        }
        $bytes = 0;
        foreach ($operations as $key => $candidate) {
            if (!is_array($candidate)
                || ($candidate['tenant_id'] ?? null) !== $reserved['tenant_id']
                || ($candidate['site_id'] ?? null) !== $reserved['site_id']
                || ($candidate['lease_generation'] ?? null) !== $reserved['lease_generation']) {
                continue;
            }
            $bytes += strlen((string) $key) + strlen(CanonicalJson::encode($candidate));
        }
        if ($bytes + self::MAX_CACHED_RESULT_BASE64_BYTES + 512
            > self::OPERATIONS_PER_GENERATION_BYTES_LIMIT) {
            throw new ControlRefusal(
                'preview lifecycle has insufficient operation bytes for the maximum result'
            );
        }
    }

    /** @param array<string,mixed> $state @return array{0:string,1:array<string,mixed>,2:array<string,mixed>} */
    private static function currentPresent(array $state, string $tenantId, string $siteId): array {
        $siteKey = self::siteKey($tenantId, $siteId);
        $site = $state['sites'][$siteKey] ?? null;
        $current = is_array($site) ? ($site['current'] ?? null) : null;
        if (!is_array($site) || !is_array($current) || ($current['state'] ?? null) !== 'present') {
            if (is_array($current) && ($current['state'] ?? null) === 'reaping') {
                throw new ControlRefusal('preview slot reap is incomplete; retry the exact destroy');
            }
            if (is_array($current) && ($current['state'] ?? null) === 'asleep') {
                throw new ControlRefusal('preview slot is asleep; wake it before issuing workload commands');
            }
            if (is_array($current) && in_array($current['state'] ?? null, ['sleeping', 'waking'], true)) {
                throw new ControlRefusal('preview slot sleep/wake transition is incomplete; retry it exactly');
            }
            throw new ControlRefusal('tenant/site has no exact present preview generation');
        }
        return [$siteKey, $site, $current];
    }

    /** @param array<string,mixed> $state @param list<string> $allowed @return array{0:string,1:array<string,mixed>,2:array<string,mixed>} */
    private static function currentInStates(
        array $state,
        string $tenantId,
        string $siteId,
        array $allowed,
        string $transition
    ): array {
        $siteKey = self::siteKey($tenantId, $siteId);
        $site = $state['sites'][$siteKey] ?? null;
        $current = is_array($site) ? ($site['current'] ?? null) : null;
        if (!is_array($site)
            || !is_array($current)
            || !in_array($current['state'] ?? null, $allowed, true)) {
            if (is_array($current) && ($current['state'] ?? null) === 'reaping') {
                throw new ControlRefusal('preview slot reap is incomplete; retry the exact destroy');
            }
            if (is_array($current) && in_array($current['state'] ?? null, ['sleeping', 'waking'], true)) {
                throw new ControlRefusal(
                    "preview slot transition is incomplete; retry the exact {$current['sleep']['action']}"
                );
            }
            throw new ControlRefusal("preview slot cannot $transition from its current state");
        }
        return [$siteKey, $site, $current];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $input @param list<string> $states @return array<string,mixed> */
    private static function requireMutation(array $current, array $input, array $states): array {
        $mutation = $current['mutation'] ?? null;
        if (!is_array($mutation)
            || !in_array($mutation['state'] ?? null, $states, true)
            || !self::mutationInputMatches(
                $input,
                $mutation,
                ($mutation['state'] ?? null) === 'released'
            )) {
            throw new ControlRefusal('request does not name the exact allowed mutation fence');
        }
        return $mutation;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $mutation */
    private static function mutationInputMatches(
        array $input,
        array $mutation,
        bool $useCurrentReceipt = false
    ): bool {
        $expectedReceipt = $useCurrentReceipt
            ? ($mutation['receipt_sha256'] ?? null)
            : ($mutation['held_receipt_sha256'] ?? null);
        return ($input['expected_mutation_generation'] ?? null) === ($mutation['generation'] ?? null)
            && ($input['expected_mutation_id'] ?? null) === ($mutation['id'] ?? null)
            && ($input['expected_mutation_owner'] ?? null) === ($mutation['owner'] ?? null)
            && ($input['expected_mutation_receipt_sha256'] ?? null) === $expectedReceipt;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $identity */
    private static function assertIdentityInput(array $input, array $identity): void {
        foreach (self::identityInputMap($identity) as $key => $expected) {
            if (($input[$key] ?? null) !== $expected) {
                throw new ControlRefusal("request differs from current lifecycle identity at '$key'");
            }
        }
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $identity */
    private static function assertOptionalIdentityInput(array $input, array $identity): void {
        if (array_key_exists('expected_environment_identity', $input)) {
            self::assertIdentityInput($input, $identity);
        }
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function identityInputMap(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function identityFromRecord(array $record): array {
        return [
            'environment_identity' => $record['environment_identity'],
            'lease_generation' => $record['lease_generation'],
            'lease_id' => $record['lease_id'],
            'ownership_receipt_sha256' => $record['ownership_receipt_sha256'],
            'resource_id' => $record['resource_id'],
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function identityResult(array $record): array {
        return self::identityFromRecord($record) + ['url' => $record['url']];
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function runtimeLease(
        array $identity,
        string $tenantId,
        string $siteId,
        string $operationId
    ): array {
        return $identity + [
            'operation_id' => $operationId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $mutation @return array<string,mixed> */
    private static function runtimeAuthority(
        array $current,
        array $mutation,
        string $tenantId,
        string $siteId,
        string $operationId
    ): array {
        return self::runtimeLease(self::identityFromRecord($current), $tenantId, $siteId, $operationId) + [
            'mutation_generation' => $mutation['generation'],
            'mutation_id' => $mutation['id'],
            'mutation_owner' => $mutation['owner'],
            'mutation_receipt_sha256' => $mutation['held_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $mutation @return array<string,mixed> */
    private static function controlTarget(array $current, array $mutation): array {
        return self::identityFromRecord($current) + [
            'mutation_generation' => $mutation['generation'],
            'mutation_id' => $mutation['id'],
            'mutation_owner' => $mutation['owner'],
            'mutation_receipt_sha256' => $mutation['held_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $mutation @return array<string,mixed> */
    private static function mutationResult(array $current, array $mutation, string $state): array {
        return self::identityResult($current) + [
            'mutation_generation' => $mutation['generation'],
            'mutation_id' => $mutation['id'],
            'mutation_owner' => $mutation['owner'],
            'mutation_receipt_sha256' => $mutation['receipt_sha256'],
            'state' => $state,
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $ttl @return array<string,mixed> */
    private static function ttlResult(array $current, array $ttl): array {
        return self::identityResult($current) + [
            'expires_at' => $ttl['expires_at'],
            'ttl_generation' => $ttl['generation'],
            'ttl_lease_id' => $ttl['lease_id'],
            'ttl_receipt_sha256' => $ttl['receipt_sha256'],
            'ttl_state' => $ttl['state'],
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function newTtl(
        array $identity,
        string $tenantId,
        string $siteId,
        string $operationId,
        int $generation,
        int $expiresAt,
        string $state
    ): array {
        if (!in_array($state, ['active', 'provisional'], true)) {
            throw new ControlRefusal('preview TTL state is invalid');
        }
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', $expiresAt);
        $ttlLeaseId = 'cloud-ttl-' . hash(
            'sha256',
            "duo-cloud-preview-ttl/v1\0$tenantId\0$siteId\0{$identity['resource_id']}\0"
            . "{$identity['lease_generation']}\0$generation\0$operationId"
        );
        $ttlReceipt = self::hashCanonical('duo-cloud-preview-ttl-receipt/v1', [
            'expires_at' => $timestamp,
            'lease_generation' => $identity['lease_generation'],
            'operation_id' => $operationId,
            'resource_id' => $identity['resource_id'],
            'site_id' => $siteId,
            'state' => $state,
            'tenant_id' => $tenantId,
            'ttl_generation' => $generation,
            'ttl_lease_id' => $ttlLeaseId,
        ]);
        return [
            'expires_at' => $timestamp,
            'generation' => $generation,
            'lease_id' => $ttlLeaseId,
            'operation_id' => $operationId,
            'receipt_sha256' => $ttlReceipt,
            'state' => $state,
        ];
    }

    /** @return array<string,mixed> */
    private static function newSite(string $tenantId, string $siteId): array {
        return [
            'current' => null,
            'last_lease_generation' => 0,
            'resource_id' => self::resourceId($tenantId, $siteId),
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
            'terminal_absence' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function newIdentity(
        string $tenantId,
        string $siteId,
        int $generation,
        string $operationId,
        string $requestHash
    ): array {
        $resourceId = self::resourceId($tenantId, $siteId);
        $environmentIdentity = 'cloud-environment-' . hash(
            'sha256',
            "duo-cloud-preview-environment/v1\0$tenantId\0$siteId"
        );
        $leaseId = 'cloud-lease-' . hash(
            'sha256',
            "duo-cloud-preview-lease/v1\0$tenantId\0$siteId\0$resourceId\0$generation\0"
            . "$operationId\0$requestHash"
        );
        $ownership = self::hashCanonical('duo-cloud-preview-ownership/v1', [
            'environment_identity' => $environmentIdentity,
            'lease_generation' => $generation,
            'lease_id' => $leaseId,
            'operation_id' => $operationId,
            'request_sha256' => $requestHash,
            'resource_id' => $resourceId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ]);
        return [
            'environment_identity' => $environmentIdentity,
            'lease_generation' => $generation,
            'lease_id' => $leaseId,
            'ownership_receipt_sha256' => $ownership,
            'resource_id' => $resourceId,
        ];
    }

    private static function resourceId(string $tenantId, string $siteId): string {
        return 'cloud-slot-' . hash(
            'sha256',
            "duo-cloud-preview-physical-slot/v1\0$tenantId\0$siteId"
        );
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $identity */
    private static function assertReceiptIdentity(array $receipt, array $identity): void {
        foreach (['environment_identity', 'lease_generation', 'resource_id'] as $key) {
            if (($receipt[$key] ?? null) !== ($identity[$key] ?? null)) {
                throw new ControlRefusal('incomplete lifecycle receipt is stale or foreign');
            }
        }
    }

    /** @param array<string,mixed> $evidence */
    private static function inspectionEvidence(array $evidence): void {
        self::exactKeys($evidence, ['evidence_sha256', 'presence', 'url'], 'workload inspection evidence');
        self::sha256($evidence['evidence_sha256'] ?? null, 'workload inspection evidence');
        if (!in_array($evidence['presence'] ?? null, ['absent', 'present'], true)) {
            throw new ControlRefusal('workload inspection presence is invalid');
        }
        self::url($evidence['url'] ?? null);
    }

    /** @param array<string,mixed> $evidence */
    private static function provisionEvidence(array $evidence): void {
        self::exactKeys($evidence, ['evidence_sha256', 'url'], 'workload provision evidence');
        self::sha256($evidence['evidence_sha256'] ?? null, 'workload provision evidence');
        self::url($evidence['url'] ?? null);
    }

    /** @param array<string,mixed> $evidence */
    private static function stageEvidence(array $evidence, string $label): void {
        self::exactKeys($evidence, ['evidence_sha256'], "$label evidence");
        self::sha256($evidence['evidence_sha256'] ?? null, "$label evidence");
    }

    /** @param array<string,mixed> $evidence */
    private static function absenceEvidence(array $evidence): void {
        self::exactKeys($evidence, ['absence_proof_sha256', 'absent'], 'workload absence evidence');
        self::sha256($evidence['absence_proof_sha256'] ?? null, 'workload absence proof');
        if (!is_bool($evidence['absent'] ?? null)) {
            throw new ControlRefusal('workload absence evidence is invalid');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function normalizedState(array $state): array {
        return $state === [] ? [
            'format' => self::STORE_FORMAT,
            'operations' => [],
            'sites' => [],
        ] : $state;
    }

    /** @param array<string,mixed> $state */
    private static function assertState(array $state): void {
        self::exactKeys($state, ['format', 'operations', 'sites'], 'preview lifecycle store');
        if (($state['format'] ?? null) !== self::STORE_FORMAT
            || !is_array($state['operations'] ?? null)
            || (array_is_list($state['operations']) && $state['operations'] !== [])
            || !is_array($state['sites'] ?? null)
            || (array_is_list($state['sites']) && $state['sites'] !== [])) {
            throw new ControlRefusal('preview lifecycle store shape or format is invalid');
        }
        foreach ($state['operations'] as $receiptKey => $receipt) {
            self::assertOperationReceipt($receiptKey, $receipt);
        }
        self::assertOperationBounds($state['operations']);
        $resources = [];
        foreach ($state['sites'] as $siteKey => $site) {
            if (!is_string($siteKey) || !is_array($site) || array_is_list($site)) {
                throw new ControlRefusal('preview lifecycle site map is invalid');
            }
            self::exactKeys($site, [
                'current',
                'last_lease_generation',
                'resource_id',
                'site_id',
                'tenant_id',
                'terminal_absence',
            ], 'preview lifecycle site');
            $tenantId = self::identifier($site['tenant_id'] ?? null, 'stored tenant id');
            $siteId = self::identifier($site['site_id'] ?? null, 'stored site id');
            $resourceId = self::identifier($site['resource_id'] ?? null, 'stored resource id');
            if ($siteKey !== self::siteKey($tenantId, $siteId)
                || $resourceId !== self::resourceId($tenantId, $siteId)
                || isset($resources[$resourceId])) {
                throw new ControlRefusal('preview lifecycle tenant/site/resource mapping aliases or collides');
            }
            $resources[$resourceId] = true;
            $lastGeneration = $site['last_lease_generation'] ?? null;
            if (!is_int($lastGeneration) || $lastGeneration < 0) {
                throw new ControlRefusal('stored preview lease generation is invalid');
            }
            $terminal = $site['terminal_absence'] ?? null;
            if ($terminal !== null) {
                self::assertTerminalAbsence($terminal, $tenantId, $siteId, $lastGeneration);
            }
            $current = $site['current'] ?? null;
            if ($current === null) {
                if (($lastGeneration === 0) !== ($terminal === null)
                    || is_array($terminal)
                    && ($terminal['lease_generation'] ?? null) !== $lastGeneration) {
                    throw new ControlRefusal('absent preview slot has invalid terminal lineage');
                }
                continue;
            }
            self::assertCurrent($current, $tenantId, $siteId, $lastGeneration);
            if (is_array($terminal)
                && ($terminal['lease_generation'] ?? PHP_INT_MAX) >= $current['lease_generation']) {
                throw new ControlRefusal('preview terminal absence does not precede current generation');
            }
        }
    }

    private static function assertOperationReceipt(mixed $receiptKey, mixed $receipt): void {
        if (!is_string($receiptKey)
            || preg_match('/^[a-f0-9]{64}$/D', $receiptKey) !== 1
            || !is_array($receipt)
            || array_is_list($receipt)) {
            throw new ControlRefusal('preview lifecycle operation map is invalid');
        }
        $status = $receipt['status'] ?? null;
        $keys = match ($status) {
            'complete' => [
                'action', 'environment_identity', 'lease_generation', 'operation_id',
                'request_sha256', 'resource_id', 'result_base64', 'result_sha256',
                'site_id', 'status', 'tenant_id',
            ],
            'superseded' => [
                'action', 'environment_identity', 'lease_generation', 'operation_id',
                'request_sha256', 'resource_id', 'site_id', 'status',
                'superseded_by_operation_id', 'superseded_by_request_sha256', 'tenant_id',
            ],
            default => [
                'action', 'environment_identity', 'lease_generation', 'operation_id',
                'request_sha256', 'resource_id', 'site_id', 'status', 'tenant_id',
            ],
        };
        self::exactKeys($receipt, $keys, 'preview lifecycle operation receipt');
        $action = self::action($receipt['action'] ?? null);
        $tenantId = self::identifier($receipt['tenant_id'] ?? null, 'receipt tenant id');
        $siteId = self::identifier($receipt['site_id'] ?? null, 'receipt site id');
        $operationId = self::operationId($receipt['operation_id'] ?? null);
        self::identifier($receipt['environment_identity'] ?? null, 'receipt environment identity');
        self::identifier($receipt['resource_id'] ?? null, 'receipt resource id');
        self::positiveInt($receipt['lease_generation'] ?? null, 'receipt lease generation');
        self::sha256($receipt['request_sha256'] ?? null, 'receipt request hash');
        if ($receiptKey !== self::receiptKey($tenantId, $siteId, $action, $operationId)
            || !in_array($status, ['executing', 'complete', 'superseded'], true)) {
            throw new ControlRefusal('preview lifecycle operation receipt identity or state is invalid');
        }
        if ($status === 'complete') {
            self::sha256($receipt['result_sha256'] ?? null, 'receipt result hash');
            self::cachedResult($receipt);
        } elseif ($status === 'superseded') {
            if (in_array($action, ['destroy', 'inspect'], true)) {
                throw new ControlRefusal('destroy cannot supersede a destroy or exact inspect receipt');
            }
            self::operationId($receipt['superseded_by_operation_id'] ?? null);
            self::sha256(
                $receipt['superseded_by_request_sha256'] ?? null,
                'superseding destroy request hash'
            );
        }
    }

    private static function assertTerminalAbsence(
        mixed $terminal,
        string $tenantId,
        string $siteId,
        int $lastGeneration
    ): void {
        if (!is_array($terminal) || array_is_list($terminal)) {
            throw new ControlRefusal('preview terminal absence is malformed');
        }
        self::exactKeys($terminal, [
            'absence_proof_sha256', 'environment_identity', 'lease_generation', 'lease_id',
            'ownership_receipt_sha256', 'resource_id', 'url',
        ], 'preview terminal absence');
        self::identityRecord($terminal, $tenantId, $siteId);
        self::sha256($terminal['absence_proof_sha256'] ?? null, 'terminal absence proof');
        self::url($terminal['url'] ?? null);
        if (!is_int($terminal['lease_generation'] ?? null)
            || $terminal['lease_generation'] > $lastGeneration) {
            throw new ControlRefusal('terminal absence generation exceeds site lineage');
        }
    }

    private static function assertCurrent(
        mixed $current,
        string $tenantId,
        string $siteId,
        int $lastGeneration
    ): void {
        if (!is_array($current) || array_is_list($current)) {
            throw new ControlRefusal('current preview generation is malformed');
        }
        self::exactKeys($current, [
            'acquisition_input_sha256', 'acquisition_operation_id', 'create_evidence_sha256',
            'environment_identity', 'evidence', 'last_mutation_generation', 'last_ttl_generation',
            'lease_generation', 'lease_id', 'mutation', 'ownership_receipt_sha256',
            'prior_absence_proof_sha256', 'reap', 'resource_id', 'sleep', 'state',
            'target_environment', 'ttl', 'url',
        ], 'current preview generation');
        self::identityRecord($current, $tenantId, $siteId);
        self::sha256($current['acquisition_input_sha256'] ?? null, 'acquisition input hash');
        self::sha256($current['prior_absence_proof_sha256'] ?? null, 'prior absence proof');
        $operationId = self::operationId($current['acquisition_operation_id'] ?? null);
        self::environment($current['target_environment'] ?? null);
        $state = $current['state'] ?? null;
        if (!in_array($state, ['acquiring', 'asleep', 'present', 'reaping', 'sleeping', 'waking'], true)
            || ($current['lease_generation'] ?? null) !== $lastGeneration) {
            throw new ControlRefusal('current preview generation state or lineage is invalid');
        }
        $expected = self::newIdentity(
            $tenantId,
            $siteId,
            $lastGeneration,
            $operationId,
            (string) $current['acquisition_input_sha256']
        );
        foreach ($expected as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                throw new ControlRefusal("current preview identity differs at '$key'");
            }
        }
        foreach (['last_mutation_generation', 'last_ttl_generation'] as $key) {
            if (!is_int($current[$key] ?? null) || $current[$key] < 0) {
                throw new ControlRefusal("current preview $key is invalid");
            }
        }
        self::assertEvidenceMap($current['evidence'] ?? null);
        self::assertMutationRecord($current['mutation'] ?? null, (int) $current['last_mutation_generation'], $state);
        self::assertTtlRecord($current['ttl'] ?? null, (int) $current['last_ttl_generation']);
        self::assertReapRecord($current['reap'] ?? null, $state);
        self::assertSleepRecord($current['sleep'] ?? null, $state);
        if ($state === 'acquiring') {
            if ($current['url'] !== null || $current['create_evidence_sha256'] !== null
                || $current['mutation'] !== null
                || $current['reap'] !== null || $current['sleep'] !== null
                || $current['evidence'] !== []) {
                throw new ControlRefusal('acquiring preview generation contains prematurely published evidence');
            }
            if (($current['ttl']['state'] ?? null) !== 'provisional') {
                throw new ControlRefusal('acquiring preview generation has no provisional service deadline');
            }
            return;
        }
        self::url($current['url'] ?? null);
        self::sha256($current['create_evidence_sha256'] ?? null, 'create evidence');
    }

    /** @param array<string,mixed> $record */
    private static function identityRecord(array $record, string $tenantId, string $siteId): void {
        self::identifier($record['environment_identity'] ?? null, 'environment identity');
        self::identifier($record['resource_id'] ?? null, 'resource id');
        self::identifier($record['lease_id'] ?? null, 'lease id');
        self::positiveInt($record['lease_generation'] ?? null, 'lease generation');
        self::sha256($record['ownership_receipt_sha256'] ?? null, 'ownership receipt');
        if (($record['resource_id'] ?? null) !== self::resourceId($tenantId, $siteId)) {
            throw new ControlRefusal('preview identity resource does not belong to tenant/site');
        }
    }

    private static function assertEvidenceMap(mixed $evidence): void {
        if (!is_array($evidence) || (array_is_list($evidence) && $evidence !== [])) {
            throw new ControlRefusal('preview workload evidence map is invalid');
        }
        foreach ($evidence as $key => $record) {
            if (!is_string($key)
                || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1
                || !is_array($record)
                || array_is_list($record)) {
                throw new ControlRefusal('preview workload evidence record is malformed');
            }
            self::exactKeys(
                $record,
                ['action', 'evidence_sha256', 'operation_id', 'request_sha256'],
                'preview workload evidence'
            );
            if (!in_array($record['action'] ?? null, [
                'repository-materialize', 'repository-sync', 'snapshot-restore', 'url-set',
            ], true)) {
                throw new ControlRefusal('preview workload evidence action is invalid');
            }
            self::sha256($record['evidence_sha256'] ?? null, 'workload evidence hash');
            self::sha256($record['request_sha256'] ?? null, 'workload request hash');
            self::operationId($record['operation_id'] ?? null);
        }
    }

    private static function assertMutationRecord(mixed $mutation, int $lastGeneration, string $currentState): void {
        if ($mutation === null) {
            if ($lastGeneration !== 0 || $currentState === 'reaping') {
                throw new ControlRefusal('preview mutation lineage is missing');
            }
            return;
        }
        if (!is_array($mutation) || array_is_list($mutation)) {
            throw new ControlRefusal('preview mutation record is malformed');
        }
        self::exactKeys($mutation, [
            'generation', 'held_receipt_sha256', 'id', 'operation_id', 'owner',
            'receipt_sha256', 'state',
        ], 'preview mutation record');
        self::positiveInt($mutation['generation'] ?? null, 'mutation generation');
        self::identifier($mutation['id'] ?? null, 'mutation id');
        self::mutationOwner($mutation['owner'] ?? null);
        self::operationId($mutation['operation_id'] ?? null);
        self::sha256($mutation['held_receipt_sha256'] ?? null, 'held mutation receipt');
        self::sha256($mutation['receipt_sha256'] ?? null, 'mutation receipt');
        if (($mutation['generation'] ?? null) !== $lastGeneration
            || !in_array($mutation['state'] ?? null, ['held', 'publishing', 'released', 'releasing', 'reaping'], true)
            || ($mutation['state'] ?? null) !== 'released'
            && $mutation['receipt_sha256'] !== $mutation['held_receipt_sha256']
            || ($currentState === 'reaping') !== (($mutation['state'] ?? null) === 'reaping')
            || in_array($currentState, ['asleep', 'sleeping', 'waking'], true)
            && ($mutation['state'] ?? null) !== 'held') {
            throw new ControlRefusal('preview mutation state or lineage is invalid');
        }
    }

    private static function assertTtlRecord(mixed $ttl, int $lastGeneration): void {
        if ($ttl === null) {
            throw new ControlRefusal('current preview generation has no service deadline');
        }
        if (!is_array($ttl) || array_is_list($ttl)) {
            throw new ControlRefusal('preview TTL record is malformed');
        }
        self::exactKeys(
            $ttl,
            ['expires_at', 'generation', 'lease_id', 'operation_id', 'receipt_sha256', 'state'],
            'preview TTL record'
        );
        self::timestamp($ttl['expires_at'] ?? null);
        self::positiveInt($ttl['generation'] ?? null, 'TTL generation');
        self::identifier($ttl['lease_id'] ?? null, 'TTL lease id');
        self::operationId($ttl['operation_id'] ?? null);
        self::sha256($ttl['receipt_sha256'] ?? null, 'TTL receipt');
        if (($ttl['generation'] ?? null) !== $lastGeneration
            || !in_array($ttl['state'] ?? null, ['active', 'provisional'], true)) {
            throw new ControlRefusal('preview TTL state or lineage is invalid');
        }
    }

    private static function assertReapRecord(mixed $reap, string $currentState): void {
        if ($reap === null) {
            if ($currentState === 'reaping') {
                throw new ControlRefusal('reaping preview generation has no intent');
            }
            return;
        }
        if (!is_array($reap) || array_is_list($reap) || $currentState !== 'reaping') {
            throw new ControlRefusal('preview reap record is malformed');
        }
        self::exactKeys($reap, [
            'control_action', 'control_released', 'delete_evidence_sha256', 'execution_evidence_sha256',
            'operation_id', 'request_sha256', 'routing_evidence_sha256', 'state',
        ], 'preview reap record');
        self::operationId($reap['operation_id'] ?? null);
        self::sha256($reap['request_sha256'] ?? null, 'reap request hash');
        if (!in_array($reap['control_action'] ?? null, ['hold-release', 'none', 'release'], true)
            || !is_bool($reap['control_released'] ?? null)
            || !in_array($reap['state'] ?? null, [
                'intent', 'control-held', 'control-released', 'routing-revoked',
                'execution-revoked', 'state-deleted',
            ], true)) {
            throw new ControlRefusal('preview reap phase is invalid');
        }
        foreach (['delete_evidence_sha256', 'execution_evidence_sha256', 'routing_evidence_sha256'] as $key) {
            if ($reap[$key] !== null) {
                self::sha256($reap[$key], "reap $key");
            }
        }
        $phase = $reap['state'];
        $expected = match ($phase) {
            'intent', 'control-held' => [false, false, false, false],
            'control-released' => [true, false, false, false],
            'routing-revoked' => [true, true, false, false],
            'execution-revoked' => [true, true, true, false],
            'state-deleted' => [true, true, true, true],
        };
        $actual = [
            $reap['control_released'],
            is_string($reap['routing_evidence_sha256']),
            is_string($reap['execution_evidence_sha256']),
            is_string($reap['delete_evidence_sha256']),
        ];
        if ($actual !== $expected) {
            throw new ControlRefusal('preview reap evidence does not match its durable phase');
        }
        if (($reap['control_action'] ?? null) === 'none' && $phase === 'intent') {
            throw new ControlRefusal('preview reap without command authority cannot await release');
        }
        if ($phase === 'control-held' && ($reap['control_action'] ?? null) !== 'hold-release') {
            throw new ControlRefusal('preview reap hold reconciliation phase is invalid');
        }
    }

    private static function assertSleepRecord(mixed $sleep, string $currentState): void {
        $transitionState = in_array($currentState, ['asleep', 'sleeping', 'waking'], true);
        if ($sleep === null) {
            if ($transitionState) {
                throw new ControlRefusal('sleeping preview generation has no durable transition');
            }
            return;
        }
        if (!is_array($sleep) || array_is_list($sleep) || !$transitionState) {
            throw new ControlRefusal('preview sleep transition record is malformed');
        }
        self::exactKeys($sleep, [
            'action', 'execution_evidence_sha256', 'operation_id', 'request_sha256',
            'routing_evidence_sha256', 'state',
        ], 'preview sleep transition');
        self::operationId($sleep['operation_id'] ?? null);
        self::sha256($sleep['request_sha256'] ?? null, 'sleep transition request hash');
        foreach (['execution_evidence_sha256', 'routing_evidence_sha256'] as $key) {
            if ($sleep[$key] !== null) {
                self::sha256($sleep[$key], "sleep transition $key");
            }
        }
        $action = $sleep['action'] ?? null;
        $phase = $sleep['state'] ?? null;
        $expected = match ($action) {
            'sleep' => match ($phase) {
                'intent' => ['sleeping', false, false],
                'routing-revoked' => ['sleeping', false, true],
                'asleep' => ['asleep', true, true],
                default => null,
            },
            'wake' => match ($phase) {
                'intent' => ['waking', false, false],
                'execution-resumed' => ['waking', true, false],
                'routing-restored' => ['waking', true, true],
                default => null,
            },
            default => null,
        };
        if (!is_array($expected)
            || $currentState !== $expected[0]
            || is_string($sleep['execution_evidence_sha256']) !== $expected[1]
            || is_string($sleep['routing_evidence_sha256']) !== $expected[2]) {
            throw new ControlRefusal('preview sleep evidence does not match its durable phase');
        }
    }

    /** @param array<string,mixed> $input */
    private static function validateExpiredReapInput(array $input): void {
        self::exactKeys($input, [
            'compare_and_reap', 'expected_environment_identity', 'expected_lease_generation',
            'expected_lease_id', 'expected_ownership_receipt_sha256', 'expected_resource_id',
        ], 'expired reap input');
        if (($input['compare_and_reap'] ?? null) !== true) {
            throw new ControlRefusal('expired reap requires exact compare-and-reap intent');
        }
        self::validateIdentityFields($input);
    }

    /** @param array<string,mixed> $input */
    private static function validateInput(string $action, array $input): void {
        $identity = [
            'expected_environment_identity', 'expected_lease_generation', 'expected_lease_id',
            'expected_ownership_receipt_sha256', 'expected_resource_id',
        ];
        $mutation = [
            'expected_mutation_generation', 'expected_mutation_id',
            'expected_mutation_owner', 'expected_mutation_receipt_sha256',
        ];
        if ($action === 'create') {
            self::exactKeys($input, ['intent_sha256', 'mode', 'target_environment'], 'create input');
            self::sha256($input['intent_sha256'] ?? null, 'create intent');
            if (($input['mode'] ?? null) !== 'create') {
                throw new ControlRefusal('create input mode must be create');
            }
            self::environment($input['target_environment'] ?? null);
            return;
        }
        if ($action === 'inspect') {
            $expected = array_key_exists('expected_environment_identity', $input)
                ? array_merge($identity, ['role'])
                : ['role'];
            self::exactKeys($input, $expected, 'inspect input');
            if (($input['role'] ?? null) !== 'target') {
                throw new ControlRefusal('inspect input role must be target');
            }
            if (count($expected) > 1) {
                self::validateIdentityFields($input);
            }
            return;
        }
        if ($action === 'mutation-acquire') {
            self::exactKeys($input, array_merge($identity, ['mutation_owner']), 'mutation-acquire input');
            self::validateIdentityFields($input);
            self::mutationOwner($input['mutation_owner'] ?? null);
            return;
        }
        $common = array_merge($identity, $mutation);
        if (in_array($action, ['mutation-read', 'mutation-release', 'sleep', 'wake'], true)) {
            self::exactKeys($input, $common, "$action input");
        } elseif ($action === 'snapshot-restore') {
            self::exactKeys(
                $input,
                array_merge($common, ['database_sha256', 'media_sha256', 'snapshot_set_id']),
                'snapshot-restore input'
            );
            self::sha256($input['database_sha256'] ?? null, 'snapshot database hash');
            self::sha256($input['media_sha256'] ?? null, 'snapshot media hash');
            self::identifier($input['snapshot_set_id'] ?? null, 'snapshot set id');
        } elseif ($action === 'repository-materialize') {
            self::exactKeys(
                $input,
                array_merge($common, [
                    'branch_commit', 'branch_ref', 'expected_repository_authority_sha256',
                    'expected_repository_sync_receipt_sha256', 'repo_path',
                ]),
                'repository-materialize input'
            );
            self::gitOid($input['branch_commit'] ?? null);
            self::gitRef($input['branch_ref'] ?? null);
            self::sha256(
                $input['expected_repository_authority_sha256'] ?? null,
                'expected repository authority'
            );
            self::sha256(
                $input['expected_repository_sync_receipt_sha256'] ?? null,
                'expected repository sync receipt'
            );
            $repoPath = self::nonEmptyString($input['repo_path'] ?? null, 4096, 'repository path');
            if ($repoPath[0] !== '/') {
                throw new ControlRefusal('repository path must be absolute inside the workload');
            }
        } elseif ($action === 'repository-sync') {
            self::exactKeys(
                $input,
                array_merge($common, [
                    'branch_commit', 'branch_ref', 'candidate_publication_receipt_sha256',
                    'repository_authority_sha256',
                ]),
                'repository-sync input'
            );
            self::gitOid($input['branch_commit'] ?? null);
            self::gitRef($input['branch_ref'] ?? null);
            self::sha256(
                $input['candidate_publication_receipt_sha256'] ?? null,
                'candidate publication receipt'
            );
            self::sha256(
                $input['repository_authority_sha256'] ?? null,
                'repository authority descriptor'
            );
        } elseif ($action === 'url-set') {
            self::exactKeys($input, array_merge($common, ['url']), 'url-set input');
            self::url($input['url'] ?? null);
        } elseif ($action === 'ttl-set') {
            self::exactKeys($input, array_merge($common, ['ttl_seconds']), 'ttl-set input');
            if (!is_int($input['ttl_seconds'] ?? null)
                || $input['ttl_seconds'] < 60
                || $input['ttl_seconds'] > 31536000) {
                throw new ControlRefusal('TTL seconds must be an integer from 60 through 31536000');
            }
        } elseif ($action === 'ttl-read') {
            self::exactKeys($input, array_merge($common, [
                'expected_expires_at', 'expected_ttl_generation',
                'expected_ttl_lease_id', 'expected_ttl_receipt_sha256',
            ]), 'ttl-read input');
            self::timestamp($input['expected_expires_at'] ?? null);
            self::positiveInt($input['expected_ttl_generation'] ?? null, 'expected TTL generation');
            self::identifier($input['expected_ttl_lease_id'] ?? null, 'expected TTL lease id');
            self::sha256($input['expected_ttl_receipt_sha256'] ?? null, 'expected TTL receipt');
        } elseif ($action === 'destroy') {
            self::exactKeys($input, array_merge($common, ['compare_and_reap']), 'destroy input');
            if (($input['compare_and_reap'] ?? null) !== true) {
                throw new ControlRefusal('destroy requires exact compare-and-reap intent');
            }
        }
        self::validateIdentityFields($input);
        self::positiveInt($input['expected_mutation_generation'] ?? null, 'expected mutation generation');
        self::identifier($input['expected_mutation_id'] ?? null, 'expected mutation id');
        self::mutationOwner($input['expected_mutation_owner'] ?? null);
        self::sha256($input['expected_mutation_receipt_sha256'] ?? null, 'expected mutation receipt');
    }

    /** @param array<string,mixed> $input */
    private static function validateIdentityFields(array $input): void {
        self::identifier($input['expected_environment_identity'] ?? null, 'expected environment identity');
        self::positiveInt($input['expected_lease_generation'] ?? null, 'expected lease generation');
        self::identifier($input['expected_lease_id'] ?? null, 'expected lease id');
        self::sha256($input['expected_ownership_receipt_sha256'] ?? null, 'expected ownership receipt');
        self::identifier($input['expected_resource_id'] ?? null, 'expected resource id');
    }

    private static function receiptKey(
        string $tenantId,
        string $siteId,
        string $action,
        string $operationId
    ): string {
        return hash(
            'sha256',
            "duo-cloud-preview-slot-receipt/v1\0$tenantId\0$siteId\0$action\0$operationId"
        );
    }

    private static function siteKey(string $tenantId, string $siteId): string {
        return hash('sha256', "duo-cloud-preview-site/v1\0$tenantId\0$siteId");
    }

    private static function hashCanonical(string $domain, mixed $value): string {
        return hash('sha256', $domain . "\0" . CanonicalJson::encode($value));
    }

    private static function action(mixed $value): string {
        if (!is_string($value) || !in_array($value, self::ACTIONS, true)) {
            throw new ControlRefusal('preview lifecycle action is unknown');
        }
        return $value;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+-]{0,127}$/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function environment(mixed $value): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new ControlRefusal('target environment is invalid');
        }
        return $value;
    }

    private static function operationId(mixed $value): string {
        if (!is_string($value) || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $value) !== 1) {
            throw new ControlRefusal('operation id is invalid');
        }
        return $value;
    }

    private static function mutationOwner(mixed $value): string {
        if (!is_string($value)
            || preg_match(
                '/^duo-env-(?:materialize|reap|sleep)-[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D',
                $value
            ) !== 1) {
            throw new ControlRefusal('mutation owner is not a closed materialize/reap/sleep operation principal');
        }
        return $value;
    }

    private static function assertReapRefenceOwner(mixed $previous, mixed $next): void {
        $prior = [];
        $following = [];
        if (!is_string($previous) || !is_string($next)
            || preg_match(
                '/^duo-env-(materialize|sleep)-([0-9]{8}-[0-9]{6}-[a-f0-9]{24})$/D',
                $previous,
                $prior
            ) !== 1
            || preg_match(
                '/^duo-env-(reap|sleep)-([0-9]{8}-[0-9]{6}-[a-f0-9]{24})$/D',
                $next,
                $following
            ) !== 1) {
            throw new ControlRefusal(
                'same-lease mutation re-fence requires a matching reap principal or sleep lineage '
                    . 'within the sleep/reap transition graph'
            );
        }
        if ($prior[2] !== $following[2]) {
            throw new ControlRefusal(
                'same-lease mutation re-fence requires a matching reap principal or sleep lineage '
                    . 'within the sleep/reap transition graph'
            );
        }
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ControlRefusal("$label must be lowercase SHA-256");
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $label): int {
        if (!is_int($value) || $value < 1) {
            throw new ControlRefusal("$label must be a positive integer");
        }
        return $value;
    }

    private static function gitOid(mixed $value): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) !== 1) {
            throw new ControlRefusal('branch commit must be a lowercase SHA-1 or SHA-256 Git object id');
        }
        return $value;
    }

    private static function gitRef(mixed $value): string {
        if (!is_string($value)
            || strlen($value) > 512
            || preg_match('#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $value) !== 1
            || str_contains($value, '..')
            || str_contains($value, '//')
            || str_contains($value, '@{')
            || str_ends_with($value, '.')
            || str_ends_with($value, '/')
            || str_ends_with($value, '.lock')) {
            throw new ControlRefusal('branch ref is outside the closed remote heads namespace');
        }
        return $value;
    }

    private static function nonEmptyString(mixed $value, int $limit, string $label): string {
        if (!is_string($value) || $value === '' || strlen($value) > $limit || str_contains($value, "\0")) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function url(mixed $value): string {
        $parts = is_string($value) ? parse_url($value) : false;
        if (!is_string($value)
            || strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new ControlRefusal('preview URL must be credential-free HTTP(S) without query or fragment');
        }
        return $value;
    }

    private static function timestamp(mixed $value): string {
        if (!is_string($value)) {
            throw new ControlRefusal('preview expiry must be canonical UTC seconds');
        }
        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC')
        );
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new ControlRefusal('preview expiry must be canonical UTC seconds');
        }
        return $value;
    }

    /** @param array<string,mixed> $descriptor */
    private static function assertReviewedBaseContainment(array $descriptor): void {
        self::exactKeys($descriptor, [
            'descriptor_sha256', 'egress_evidence', 'format', 'image_reference',
            'reviewed_base', 'routing_evidence', 'runtime_configuration_sha256',
            'seccomp_profile_sha256', 'secrets_evidence', 'storage_evidence',
        ], 'reviewed-base containment descriptor');
        if (($descriptor['format'] ?? null) !== self::REVIEWED_CONTAINMENT_FORMAT
            || ($descriptor['egress_evidence'] ?? null)
                !== 'host-nft-input-forward-default-deny-readback/v1'
            || ($descriptor['routing_evidence'] ?? null) !== 'credential-free-route-authority-readback/v1'
            || ($descriptor['secrets_evidence'] ?? null)
                !== 'generation-private-files-readonly-mount-readback/v1'
            || ($descriptor['storage_evidence'] ?? null)
                !== 'dm-crypt-xfs-project-quota-exact-readback/v1'
            || !ImmutableOciReference::valid($descriptor['image_reference'] ?? null)) {
            throw new ControlRefusal('reviewed-base containment descriptor is malformed');
        }
        self::sha256($descriptor['descriptor_sha256'] ?? null, 'reviewed-base containment descriptor');
        self::sha256(
            $descriptor['runtime_configuration_sha256'] ?? null,
            'reviewed-base runtime configuration'
        );
        self::sha256(
            $descriptor['seccomp_profile_sha256'] ?? null,
            'reviewed-base seccomp profile'
        );
        $base = $descriptor['reviewed_base'] ?? null;
        if (!is_array($base) || array_is_list($base)) {
            throw new ControlRefusal('reviewed-base containment descriptor has no reviewed base');
        }
        self::exactKeys($base, [
            'format', 'image_digest', 'platform_fingerprint_sha256', 'review_receipt_sha256',
        ], 'reviewed preview base');
        $reference = (string) $descriptor['image_reference'];
        $at = strrpos($reference, '@');
        if (($base['format'] ?? null) !== self::REVIEWED_BASE_FORMAT
            || !is_string($base['image_digest'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $base['image_digest']) !== 1
            || !is_int($at)
            || substr($reference, $at + 1) !== $base['image_digest']) {
            throw new ControlRefusal('reviewed-base containment image does not match its reviewed base');
        }
        self::sha256($base['platform_fingerprint_sha256'] ?? null, 'reviewed-base platform fingerprint');
        self::sha256($base['review_receipt_sha256'] ?? null, 'reviewed-base review receipt');
        $basis = $descriptor;
        $claimed = (string) $basis['descriptor_sha256'];
        unset($basis['descriptor_sha256']);
        $expected = hash(
            'sha256',
            self::REVIEWED_CONTAINMENT_FORMAT . "\0" . CanonicalJson::encode($basis)
        );
        if (!hash_equals($expected, $claimed)) {
            throw new ControlRefusal('reviewed-base containment descriptor hash does not verify');
        }
    }

    /** @param array<string,mixed> $descriptor */
    private static function assertRepositoryAuthority(array $descriptor): void {
        self::exactKeys(
            $descriptor,
            [
                'credential_helper_sha256', 'descriptor_sha256', 'format',
                'ref_prefix', 'remote_url_sha256',
            ],
            'repository authority descriptor'
        );
        if (($descriptor['format'] ?? null) !== self::REPOSITORY_AUTHORITY_FORMAT
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
            throw new ControlRefusal('repository authority descriptor is malformed');
        }
        self::sha256($descriptor['descriptor_sha256'] ?? null, 'repository authority descriptor');
        self::sha256(
            $descriptor['credential_helper_sha256'] ?? null,
            'repository credential helper digest'
        );
        self::sha256($descriptor['remote_url_sha256'] ?? null, 'repository remote URL digest');
        $basis = $descriptor;
        $claimed = (string) $basis['descriptor_sha256'];
        unset($basis['descriptor_sha256']);
        $expected = hash(
            'sha256',
            self::REPOSITORY_AUTHORITY_FORMAT . "\0" . CanonicalJson::encode($basis)
        );
        if (!hash_equals($expected, $claimed)) {
            throw new ControlRefusal('repository authority descriptor hash does not verify');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal("$label has missing or unknown fields");
        }
    }
}
