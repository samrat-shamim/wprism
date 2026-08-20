<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(OriginPairing::class, false)) {
    require_once __DIR__ . '/OriginPairing.php';
}
if (!class_exists(OriginCloudRefusal::class, false)) {
    require_once __DIR__ . '/OriginCloudClient.php';
}
if (!class_exists(OriginStore::class, false)) {
    require_once __DIR__ . '/OriginStore.php';
}
if (!class_exists(OriginUploadJournal::class, false)) {
    require_once __DIR__ . '/OriginUploadJournal.php';
}
if (!interface_exists(OriginUploadSource::class, false)) {
    require_once __DIR__ . '/OriginUploadSource.php';
}

/**
 * Crash-safe outbound origin observation and upload state machine.
 *
 * Every network operation has a durable intent phase. OriginCloudClient then
 * derives its request id solely from journaled identities, so loss after the
 * authority commits a response replays the identical signed request. The one
 * target-reading transition is `accepted` -> `sealed`: acceptance is durable
 * first, and OriginExporter itself treats a completed spool as terminal local
 * evidence before Git, Policy, WordPress, or DB access.
 */
final class OriginUploadCoordinator {
    private const STATE_FORMAT = 'duo-cloud-origin-upload-state/v1';
    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const MAX_TRANSITIONS = 512;

    private OriginUploadSource $source;

    public function __construct(
        private OriginPairing $pairing,
        private OriginUploadJournal $journal,
        private string $repository,
        ?OriginUploadSource $source = null
    ) {
        self::assertControlPlane();
        if ($repository === '' || $repository[0] !== '/' || str_contains($repository, "\0")) {
            throw new \RuntimeException('duo: cloud origin upload repository is invalid');
        }
        $this->repository = rtrim($repository, '/');
        if ($this->repository === '') {
            throw new \RuntimeException('duo: cloud origin upload repository is invalid');
        }
        $this->source = $source ?? new OriginUploadStoreSource();
    }

    public static function production(string $repository): self {
        self::assertControlPlane();
        $store = OriginStore::open($repository);
        $resolved = $store->repository();
        return new self(
            OriginPairing::production($resolved),
            new OriginUploadJournal($resolved . '/.duo/control/cloud-origin/upload'),
            $resolved
        );
    }

    /**
     * Begin an initial or terminal replacement pairing only while no sealed
     * production export can survive under the prior signing authority.
     *
     * @param array<string,mixed> $connector
     * @return array<string,mixed> redacted pairing status
     */
    public function beginPairing(string $deviceCode, array $connector): array {
        self::assertControlPlane();

        return $this->journal->locked(function (OriginUploadJournalSession $session) use (
            $deviceCode,
            $connector
        ): array {
            $state = $session->state();
            if ($state !== null) {
                $this->assertState($state);
                if (!hash_equals($state['repository_sha256'], hash('sha256', $this->repository))) {
                    throw new \RuntimeException(
                        'duo: cloud origin upload journal belongs to another repository or site'
                    );
                }
                if ($state['phase'] !== 'idle' || $state['active'] !== null
                    || $state['revocation_reason'] !== null) {
                    throw new \RuntimeException(
                        'duo: cloud origin pairing requires an idle upload journal'
                    );
                }
            }

            $this->pairing->begin($deviceCode, $connector);
            return $this->pairing->status();
        });
    }

    /**
     * Poll once when idle, or drive the single active export to a durable
     * commit receipt. Retriable transport failures intentionally escape.
     *
     * @return array<string,mixed> redacted progress; never export bytes or credentials
     */
    public function run(): array {
        // Web/plugin bootstrap must be rejected before pairing, journal, repo,
        // Git, WordPress, DB, or network access.
        self::assertControlPlane();

        return $this->journal->locked(function (OriginUploadJournalSession $session): array {
            $authority = $this->pairedAuthority();
            $client = $this->pairing->pairedClient();
            if (!hash_equals($authority['origin_key_id'], $client->originKeyId())) {
                throw new \RuntimeException('duo: cloud origin upload client does not match paired authority');
            }

            $state = $session->state();
            if ($state === null) {
                $state = $this->initialState($authority);
                $this->assertState($state);
                $session->save($state);
            } else {
                $this->assertState($state);
                $state = $this->bindAuthority($state, $authority, $session);
            }
            if ($state['revocation_reason'] !== null) {
                throw new \RuntimeException(
                    'duo: cloud origin upload cleanup requires its matching revocation command'
                );
            }

            for ($transitions = 0; $transitions < self::MAX_TRANSITIONS; $transitions++) {
                $active = $state['active'];
                if ($state['phase'] === 'idle') {
                    $state['phase'] = 'demand_polling';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'demand_polling') {
                    $response = $client->demandPoll(
                        $state['tenant_id'],
                        $state['site_id'],
                        $state['origin_generation'],
                        $state['after_demand_generation'],
                        $state['poll_sequence']
                    );
                    if ($response['state'] !== 'demanded') {
                        $state['phase'] = 'idle';
                        $state['poll_sequence']++;
                        $this->save($session, $state);
                        return $this->view(
                            $state,
                            (string) $response['state'],
                            (int) $response['poll_after_seconds']
                        );
                    }
                    $demand = $response['demand'];
                    if (!is_array($demand) || array_is_list($demand)) {
                        throw new \RuntimeException('duo: cloud origin upload received no accepted demand');
                    }
                    $generation = (int) $demand['demand_generation'];
                    $expectedCommit = (string) $demand['expected_production_commit'];
                    $demandId = (string) $demand['demand_id'];
                    $state['active'] = [
                        'chunk_index' => null,
                        'chunk_sha256' => null,
                        'demand_generation' => $generation,
                        'demand_id' => $demandId,
                        'demand_sha256' => hash('sha256', Canon::encode($demand)),
                        'expected_production_commit' => $expectedCommit,
                        'export_id' => null,
                        'manifest_sha256' => null,
                        'missing_query_sequence' => 0,
                        'session_id' => self::sessionId(
                            $state['tenant_id'],
                            $state['site_id'],
                            $state['origin_generation'],
                            $generation,
                            $demandId,
                            $expectedCommit
                        ),
                        'terminal_reason' => null,
                    ];
                    $state['after_demand_generation'] = $generation;
                    $state['phase'] = 'accepted';
                    $state['poll_sequence']++;
                    // The accepted signed demand and deterministic session are
                    // durable before the only call allowed to touch the target.
                    $this->save($session, $state);
                    continue;
                }
                if (!is_array($active)) {
                    throw new \RuntimeException('duo: cloud origin upload active phase has no demand');
                }
                if ($state['phase'] === 'accepted') {
                    $this->source->seal(
                        $this->repository,
                        $active['session_id'],
                        $active['expected_production_commit']
                    );
                    $state['phase'] = 'sealed';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'sealed') {
                    $manifest = $this->manifest($active);
                    $state['active']['manifest_sha256'] = $manifest['manifest_sha256'];
                    $state['phase'] = 'announcing';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'announcing') {
                    $active = $state['active'];
                    if (!is_array($active)) {
                        throw new \RuntimeException('duo: cloud origin upload lost its active announce');
                    }
                    $manifest = $this->manifest($active);
                    if (!hash_equals($active['manifest_sha256'], $manifest['manifest_sha256'])) {
                        throw new \RuntimeException('duo: cloud origin upload sealed manifest changed after intent');
                    }
                    $response = $this->cloudRequest($session, $state, static fn(): array => $client->announce(
                        $state['tenant_id'],
                        $state['site_id'],
                        $state['origin_generation'],
                        $active['demand_generation'],
                        $active['demand_id'],
                        $manifest
                    ));
                    if ($response === null) {
                        continue;
                    }
                    $state['active']['export_id'] = $response['export_id'];
                    $state['phase'] = 'announced';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'announced') {
                    $state['phase'] = 'missing_polling';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'missing_polling') {
                    $manifest = $this->manifest($active);
                    $descriptors = self::chunkDescriptors($manifest);
                    $announced = array_keys($descriptors);
                    sort($announced, SORT_STRING);
                    $response = $this->cloudRequest($session, $state, static fn(): array => $client->missing(
                        $state['tenant_id'],
                        $state['site_id'],
                        $state['origin_generation'],
                        $active['demand_generation'],
                        $active['demand_id'],
                        $active['export_id'],
                        $active['manifest_sha256'],
                        $active['missing_query_sequence'],
                        $announced
                    ));
                    if ($response === null) {
                        continue;
                    }
                    $missing = $response['missing_chunk_sha256'];
                    if (!is_array($missing) || !array_is_list($missing)) {
                        throw new \RuntimeException('duo: cloud origin upload missing response is malformed');
                    }
                    $state['active']['missing_query_sequence']++;
                    if ($missing === []) {
                        $state['phase'] = 'committing';
                    } else {
                        $sha256 = (string) $missing[0];
                        if (!isset($descriptors[$sha256])) {
                            throw new \RuntimeException('duo: cloud origin upload missing response escaped its manifest');
                        }
                        $state['active']['chunk_index'] = $descriptors[$sha256];
                        $state['active']['chunk_sha256'] = $sha256;
                        $state['phase'] = 'uploading';
                    }
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'uploading') {
                    $manifest = $this->manifest($active);
                    $descriptors = self::chunkDescriptors($manifest);
                    $sha256 = $active['chunk_sha256'];
                    $index = $active['chunk_index'];
                    if (!is_string($sha256) || !is_int($index)
                        || ($descriptors[$sha256] ?? null) !== $index) {
                        throw new \RuntimeException('duo: cloud origin upload chunk intent escaped its manifest');
                    }
                    $bytes = $this->source->readChunk(
                        $this->repository,
                        $active['session_id'],
                        $active['expected_production_commit'],
                        $index,
                        $sha256
                    );
                    try {
                        $response = $this->cloudRequest($session, $state, static fn(): array => $client->putChunk(
                            $state['tenant_id'],
                            $state['site_id'],
                            $state['origin_generation'],
                            $active['demand_generation'],
                            $active['demand_id'],
                            $active['export_id'],
                            $active['manifest_sha256'],
                            $sha256,
                            $bytes
                        ));
                    } finally {
                        if ($bytes !== '') {
                            sodium_memzero($bytes);
                        }
                    }
                    if ($response === null) {
                        continue;
                    }
                    $state['active']['chunk_index'] = null;
                    $state['active']['chunk_sha256'] = null;
                    $state['phase'] = 'announced';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'committing') {
                    $response = $this->cloudRequest($session, $state, static fn(): array => $client->commit(
                        $state['tenant_id'],
                        $state['site_id'],
                        $state['origin_generation'],
                        $active['demand_generation'],
                        $active['demand_id'],
                        $active['export_id'],
                        $active['manifest_sha256']
                    ));
                    if ($response === null) {
                        continue;
                    }
                    $state['last_commit'] = [
                        'commit_receipt_sha256' => $response['commit_receipt_sha256'],
                        'demand_generation' => $active['demand_generation'],
                        'demand_id' => $active['demand_id'],
                        'export_id' => $active['export_id'],
                        'manifest_sha256' => $active['manifest_sha256'],
                        'retention_deadline' => $response['retention_deadline'],
                        'snapshot_hash' => $response['snapshot_hash'],
                    ];
                    $state['phase'] = 'cleaning';
                    $this->save($session, $state);
                    continue;
                }
                if ($state['phase'] === 'cleaning') {
                    if (in_array($active['terminal_reason'], [
                        'administrator_requested', 'uninstall',
                    ], true)) {
                        throw new \RuntimeException(
                            'duo: cloud origin upload cleanup requires its matching revocation command'
                        );
                    }
                    $this->source->discard(
                        $this->repository,
                        $active['session_id'],
                        $active['expected_production_commit']
                    );
                    $remoteState = $active['terminal_reason'] ?? 'committed';
                    $state['active'] = null;
                    $state['phase'] = 'idle';
                    $this->save($session, $state);
                    return $this->view($state, $remoteState, null);
                }
                throw new \RuntimeException('duo: cloud origin upload journal phase is unsupported');
            }
            throw new \RuntimeException('duo: cloud origin upload exceeded its bounded transition count');
        });
    }

    /**
     * Abandon any local immutable session before revoking its remote signing
     * authority. The upload lock spans both operations: otherwise another
     * exporter could publish a spool after cleanup but before key revocation,
     * leaving bytes that no future paired command can identify and discard.
     *
     * @return array<string,mixed> redacted pairing status
     */
    public function revoke(): array {
        return $this->revokeWithReason('administrator_requested');
    }

    /**
     * Perform or recover the pairing's one deterministic signing-key rollover
     * only when no local upload transition can still publish under the prior
     * generation. A later planned rollover requires fresh pairing authority.
     * The journal lock spans the pairing state machine and generation rebind,
     * so run() cannot accept a demand between the idle proof and the durable
     * rotated authority.
     *
     * @return array<string,mixed> redacted pairing status
     */
    public function rotate(): array {
        self::assertControlPlane();

        return $this->journal->locked(function (OriginUploadJournalSession $session): array {
            $state = $session->state();
            if ($state !== null) {
                $this->assertState($state);
                if (!hash_equals($state['repository_sha256'], hash('sha256', $this->repository))) {
                    throw new \RuntimeException('duo: cloud origin upload journal belongs to another repository or site');
                }
                if ($state['phase'] !== 'idle' || $state['active'] !== null
                    || $state['revocation_reason'] !== null) {
                    throw new \RuntimeException(
                        'duo: cloud origin key rotation requires an idle upload journal'
                    );
                }
            }

            $beforeStatus = $this->pairing->status();
            $before = $this->pairingAuthority(['paired', 'rotating'], $beforeStatus);
            if ($state !== null
                && ($state['tenant_id'] !== $before['tenant_id']
                    || $state['site_id'] !== $before['site_id'])) {
                throw new \RuntimeException(
                    'duo: cloud origin upload journal belongs to another repository or site'
                );
            }
            if ($state !== null) {
                $sameAuthority = $state['origin_generation'] === $before['origin_generation']
                    && hash_equals($state['origin_key_id'], $before['origin_key_id']);
                if (!$sameAuthority) {
                    $oneGenerationDrift = ($beforeStatus['phase'] ?? null) === 'paired'
                        && $state['origin_generation'] < self::MAX_SAFE_INTEGER
                        && $before['origin_generation'] === $state['origin_generation'] + 1
                        && !hash_equals($state['origin_key_id'], $before['origin_key_id']);
                    if (!$oneGenerationDrift) {
                        throw new \RuntimeException(
                            'duo: cloud origin upload authority drift is not one completed rotation'
                        );
                    }
                    // OriginPairing's own encrypted state distinguishes a
                    // cached completed rotation from a fresh re-pair. Calling
                    // it below replays N+1 in the former case and rotates the
                    // current N+1 authority in the latter.
                }
            }

            // OriginPairing persists both keypairs and deterministic rotation
            // identity before I/O. A lost response therefore leaves `rotating`
            // and an exact retry under this same upload serialization lock.
            $this->pairing->rotate();
            $authority = $this->pairedAuthority();
            if ($state === null) {
                $state = $this->initialState($authority);
                $this->save($session, $state);
            } else {
                $sameAuthority = $authority['origin_generation'] === $before['origin_generation']
                    && hash_equals($authority['origin_key_id'], $before['origin_key_id']);
                $advancedAuthority = $before['origin_generation'] < self::MAX_SAFE_INTEGER
                    && $authority['origin_generation'] === $before['origin_generation'] + 1
                    && !hash_equals($authority['origin_key_id'], $before['origin_key_id']);
                if (!$sameAuthority && !$advancedAuthority) {
                    throw new \RuntimeException(
                        'duo: cloud origin key rotation returned unexpected authority drift'
                    );
                }
                $this->bindAuthority($state, $authority, $session);
            }
            return $this->pairing->status();
        });
    }

    /**
     * Remove any recoverable local export before signing the uninstall
     * revocation. This is intentionally separate from administrator revoke:
     * the reason is part of the deterministic remote request identity.
     *
     * @return array<string,mixed> redacted pairing status
     */
    public function uninstall(): array {
        return $this->revokeWithReason('uninstall');
    }

    /** @return array<string,mixed> */
    public function status(): array {
        self::assertControlPlane();
        return $this->journal->locked(function (OriginUploadJournalSession $session): array {
            $state = $session->state();
            if ($state === null) {
                return [
                    'active_demand_generation' => null,
                    'after_demand_generation' => 0,
                    'last_commit' => null,
                    'origin_generation' => null,
                    'phase' => 'uninitialized',
                    'poll_after_seconds' => null,
                    'poll_sequence' => 0,
                    'remote_state' => null,
                    'site_id' => null,
                    'tenant_id' => null,
                ];
            }
            $this->assertState($state);
            return $this->view($state, null, null);
        });
    }

    /** @return array<string,mixed> */
    private function pairedAuthority(): array {
        $status = $this->pairing->status();
        return $this->pairingAuthority(['paired'], $status);
    }

    /**
     * @param list<string> $allowedPhases
     * @param ?array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function pairingAuthority(array $allowedPhases, ?array $status = null): array {
        $status ??= $this->pairing->status();
        if (!in_array($status['phase'] ?? null, $allowedPhases, true)
            || !is_string($status['origin_key_id'] ?? null)
            || !self::sha256($status['origin_key_id'])
            || !is_array($status['pairing'] ?? null)
            || array_is_list($status['pairing'])) {
            throw new \RuntimeException('duo: cloud origin upload requires active signed pairing');
        }
        $pairing = $status['pairing'];
        foreach (['site_id', 'tenant_id'] as $key) {
            if (!is_string($pairing[$key] ?? null) || !self::identifier($pairing[$key])) {
                throw new \RuntimeException('duo: cloud origin upload pairing authority is malformed');
            }
        }
        foreach (['demand_generation', 'origin_generation'] as $key) {
            if (!is_int($pairing[$key] ?? null)
                || $pairing[$key] < ($key === 'origin_generation' ? 1 : 0)) {
                throw new \RuntimeException('duo: cloud origin upload pairing generation is malformed');
            }
        }
        return [
            'demand_generation' => $pairing['demand_generation'],
            'origin_generation' => $pairing['origin_generation'],
            'origin_key_id' => $status['origin_key_id'],
            'site_id' => $pairing['site_id'],
            'tenant_id' => $pairing['tenant_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function revokeWithReason(string $reason): array {
        self::assertControlPlane();
        if (!in_array($reason, ['administrator_requested', 'uninstall'], true)) {
            throw new \LogicException('origin revocation reason escaped its closed command set');
        }

        return $this->journal->locked(function (OriginUploadJournalSession $session) use ($reason): array {
            $state = $session->state();
            if ($state !== null) {
                $this->assertState($state);
                if (!hash_equals($state['repository_sha256'], hash('sha256', $this->repository))) {
                    throw new \RuntimeException('duo: cloud origin upload journal belongs to another repository or site');
                }
                if ($state['revocation_reason'] !== null
                    && $state['revocation_reason'] !== $reason) {
                    throw new \RuntimeException(
                        'duo: cloud origin upload cleanup binds another revocation reason'
                    );
                }
                if (is_array($state['active'])) {
                    $active = $state['active'];
                    $terminalReason = $active['terminal_reason'];
                    if ($terminalReason !== null && $terminalReason !== $reason) {
                        throw new \RuntimeException(
                            'duo: cloud origin upload cleanup binds another revocation reason'
                        );
                    }
                    if ($terminalReason === null) {
                        $state['active']['chunk_index'] = null;
                        $state['active']['chunk_sha256'] = null;
                        $state['active']['terminal_reason'] = $reason;
                        $state['phase'] = 'cleaning';
                        $state['revocation_reason'] = $reason;
                        // The abandon authority is durable before deletion. A crash
                        // in OriginStore's tombstone protocol therefore resumes the
                        // same identity without exposing an upload request again.
                        $this->save($session, $state);
                    }
                    $this->source->discard(
                        $this->repository,
                        $active['session_id'],
                        $active['expected_production_commit']
                    );
                } elseif ($state['revocation_reason'] === null) {
                    $state['revocation_reason'] = $reason;
                    if ($state['phase'] === 'demand_polling') {
                        $state['phase'] = 'idle';
                    }
                    $this->save($session, $state);
                }
            }

            // OriginPairing owns the deterministic revoke id and its exact
            // response-loss replay. Keep both the upload lock and its cleaning
            // intent until pairing is terminal: clearing first left a crash
            // window where run() could publish under authority being removed.
            $this->pairing->revoke($reason);
            if ($state !== null && $state['revocation_reason'] === $reason) {
                $state['active'] = null;
                $state['phase'] = 'idle';
                $state['revocation_reason'] = null;
                $this->save($session, $state);
            }
            return $this->pairing->status();
        });
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    private function initialState(array $authority): array {
        return [
            'active' => null,
            'after_demand_generation' => $authority['demand_generation'],
            'format' => self::STATE_FORMAT,
            'last_commit' => null,
            'origin_generation' => $authority['origin_generation'],
            'origin_key_id' => $authority['origin_key_id'],
            'phase' => 'idle',
            'poll_sequence' => 0,
            'repository_sha256' => hash('sha256', $this->repository),
            'revocation_reason' => null,
            'site_id' => $authority['site_id'],
            'tenant_id' => $authority['tenant_id'],
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $authority
     * @return array<string,mixed>
     */
    private function bindAuthority(
        array $state,
        array $authority,
        OriginUploadJournalSession $session
    ): array {
        if (!hash_equals($state['repository_sha256'], hash('sha256', $this->repository))
            || $state['tenant_id'] !== $authority['tenant_id']
            || $state['site_id'] !== $authority['site_id']) {
            throw new \RuntimeException('duo: cloud origin upload journal belongs to another repository or site');
        }
        $sameGeneration = $state['origin_generation'] === $authority['origin_generation']
            && hash_equals($state['origin_key_id'], $authority['origin_key_id']);
        if ($sameGeneration) {
            return $state;
        }
        if ($state['active'] !== null || $state['phase'] !== 'idle'
            || $state['revocation_reason'] !== null) {
            throw new \RuntimeException('duo: cloud origin authority changed during an active upload');
        }
        $state['origin_generation'] = $authority['origin_generation'];
        $state['origin_key_id'] = $authority['origin_key_id'];
        $state['after_demand_generation'] = max(
            $state['after_demand_generation'],
            $authority['demand_generation']
        );
        $state['poll_sequence'] = 0;
        $this->save($session, $state);
        return $state;
    }

    /** @param array<string,mixed> $active @return array<string,mixed> */
    private function manifest(array $active): array {
        $manifest = $this->source->manifest(
            $this->repository,
            $active['session_id'],
            $active['expected_production_commit'],
            $active['demand_generation']
        );
        self::assertManifest($manifest, $active);
        return $manifest;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function view(array $state, ?string $remoteState, ?int $pollAfter): array {
        $active = $state['active'];
        return [
            'active_demand_generation' => is_array($active) ? $active['demand_generation'] : null,
            'after_demand_generation' => $state['after_demand_generation'],
            'last_commit' => $state['last_commit'],
            'origin_generation' => $state['origin_generation'],
            'phase' => $state['phase'],
            'poll_after_seconds' => $pollAfter,
            'poll_sequence' => $state['poll_sequence'],
            'remote_state' => $remoteState,
            'site_id' => $state['site_id'],
            'tenant_id' => $state['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $state */
    private function save(OriginUploadJournalSession $session, array $state): void {
        $this->assertState($state);
        $session->save($state);
    }

    /**
     * Only a service-signed, terminal generation refusal may abandon sealed
     * bytes. Unsigned transport failures and recoverable refusals preserve the
     * exact active request for replay.
     *
     * @param callable():array<string,mixed> $request
     * @param array<string,mixed> $state
     * @return ?array<string,mixed>
     */
    private function cloudRequest(
        OriginUploadJournalSession $session,
        array &$state,
        callable $request
    ): ?array {
        try {
            return $request();
        } catch (OriginCloudRefusal $error) {
            if ($state['active'] === null || $error->retryable()
                || !in_array($error->reasonCode(), [
                    'demand_expired', 'origin_revoked',
                    'stale_demand_generation', 'stale_origin_generation',
                ], true)) {
                throw $error;
            }
            // A signed terminal refusal abandons the whole immutable session.
            // Its phase-local chunk cursor cannot survive into the closed
            // cleanup state, because no later request may resume that upload.
            $state['active']['chunk_index'] = null;
            $state['active']['chunk_sha256'] = null;
            $state['active']['terminal_reason'] = $error->reasonCode();
            $state['phase'] = 'cleaning';
            $this->save($session, $state);
            return null;
        }
    }

    /** @param array<string,mixed> $state */
    private function assertState(array $state): void {
        self::exactKeys($state, [
            'active', 'after_demand_generation', 'format', 'last_commit',
            'origin_generation', 'origin_key_id', 'phase', 'poll_sequence',
            'repository_sha256', 'revocation_reason', 'site_id', 'tenant_id',
        ], 'upload state');
        if (($state['format'] ?? null) !== self::STATE_FORMAT
            || !self::nonNegativeInteger($state['after_demand_generation'] ?? null)
            || !self::positiveInteger($state['origin_generation'] ?? null)
            || !self::sha256($state['origin_key_id'] ?? null)
            || !self::sha256($state['repository_sha256'] ?? null)
            || !self::nonNegativeInteger($state['poll_sequence'] ?? null)
            || !self::identifier($state['tenant_id'] ?? null)
            || !self::identifier($state['site_id'] ?? null)
            || !in_array($state['phase'] ?? null, [
                'accepted', 'announced', 'announcing', 'committing',
                'cleaning', 'demand_polling', 'idle', 'missing_polling', 'sealed', 'uploading',
            ], true)) {
            throw new \RuntimeException('duo: cloud origin upload state is malformed');
        }
        if ($state['revocation_reason'] !== null
            && !in_array($state['revocation_reason'], [
                'administrator_requested', 'uninstall',
            ], true)) {
            throw new \RuntimeException('duo: cloud origin upload revocation reason is malformed');
        }
        if ($state['active'] === null) {
            if (!in_array($state['phase'], ['idle', 'demand_polling'], true)) {
                throw new \RuntimeException('duo: cloud origin upload terminal state has an active phase');
            }
        } else {
            self::assertActive($state['active'], $state['phase'], $state['after_demand_generation']);
            $lifecycleReason = $state['active']['terminal_reason'];
            if (in_array($lifecycleReason, [
                'administrator_requested', 'uninstall',
            ], true) && $state['revocation_reason'] !== $lifecycleReason) {
                throw new \RuntimeException(
                    'duo: cloud origin upload cleanup and revocation reason disagree'
                );
            }
            if ($state['revocation_reason'] !== null
                && $lifecycleReason !== $state['revocation_reason']) {
                throw new \RuntimeException(
                    'duo: cloud origin upload revocation intent has no matching cleanup'
                );
            }
            if ($state['phase'] === 'cleaning' && $state['active']['terminal_reason'] === null
                && (!is_array($state['last_commit'])
                    || ($state['last_commit']['demand_generation'] ?? null)
                        !== $state['active']['demand_generation'])) {
                throw new \RuntimeException('duo: cloud origin upload committed cleanup has no matching receipt');
            }
        }
        if ($state['last_commit'] !== null) {
            self::assertCommitReceipt($state['last_commit']);
            if ($state['last_commit']['demand_generation'] > $state['after_demand_generation']) {
                throw new \RuntimeException('duo: cloud origin upload receipt exceeds its demand cursor');
            }
        }
    }

    private static function assertActive(mixed $active, string $phase, int $afterGeneration): void {
        if (!is_array($active) || array_is_list($active)) {
            throw new \RuntimeException('duo: cloud origin upload active demand is malformed');
        }
        self::exactKeys($active, [
            'chunk_index', 'chunk_sha256', 'demand_generation', 'demand_id',
            'demand_sha256', 'expected_production_commit', 'export_id',
            'manifest_sha256', 'missing_query_sequence', 'session_id', 'terminal_reason',
        ], 'active upload');
        if (!self::positiveInteger($active['demand_generation'] ?? null)
            || $active['demand_generation'] !== $afterGeneration
            || !self::sha256($active['demand_id'] ?? null)
            || !self::sha256($active['demand_sha256'] ?? null)
            || !self::commit($active['expected_production_commit'] ?? null)
            || !self::nonNegativeInteger($active['missing_query_sequence'] ?? null)
            || !self::sha256($active['session_id'] ?? null)) {
            throw new \RuntimeException('duo: cloud origin upload active demand identity is malformed');
        }
        $manifestRequired = in_array($phase, [
            'announced', 'announcing', 'committing', 'missing_polling', 'uploading',
        ], true);
        $validManifest = is_string($active['manifest_sha256'])
            && self::sha256($active['manifest_sha256']);
        if (($phase === 'cleaning' && $active['manifest_sha256'] !== null && !$validManifest)
            || ($phase !== 'cleaning' && $manifestRequired !== $validManifest)) {
            throw new \RuntimeException('duo: cloud origin upload manifest intent is incomplete');
        }
        $hasExport = in_array($phase, ['announced', 'committing', 'missing_polling', 'uploading'], true);
        $validExport = is_string($active['export_id']) && self::sha256($active['export_id']);
        if ($hasExport !== $validExport
            && !($phase === 'cleaning' && ($active['export_id'] === null || $validExport))) {
            throw new \RuntimeException('duo: cloud origin upload export identity is incomplete');
        }
        if ($phase === 'uploading') {
            if (!is_int($active['chunk_index']) || $active['chunk_index'] < 0
                || !self::sha256($active['chunk_sha256'])) {
                throw new \RuntimeException('duo: cloud origin upload chunk intent is incomplete');
            }
        } elseif ($active['chunk_index'] !== null || $active['chunk_sha256'] !== null) {
            throw new \RuntimeException('duo: cloud origin upload non-chunk phase retained a chunk intent');
        }
        if ($active['terminal_reason'] !== null
            && (!is_string($active['terminal_reason']) || !in_array($active['terminal_reason'], [
                'administrator_requested', 'demand_expired', 'origin_revoked',
                'stale_demand_generation', 'stale_origin_generation', 'uninstall',
            ], true))) {
            throw new \RuntimeException('duo: cloud origin upload terminal reason is malformed');
        }
        if ($active['terminal_reason'] !== null && $phase !== 'cleaning') {
            throw new \RuntimeException('duo: cloud origin upload terminal cleanup state is malformed');
        }
    }

    private static function assertCommitReceipt(mixed $receipt): void {
        if (!is_array($receipt) || array_is_list($receipt)) {
            throw new \RuntimeException('duo: cloud origin upload commit receipt is malformed');
        }
        self::exactKeys($receipt, [
            'commit_receipt_sha256', 'demand_generation', 'demand_id',
            'export_id', 'manifest_sha256', 'retention_deadline', 'snapshot_hash',
        ], 'commit receipt');
        if (!self::sha256($receipt['commit_receipt_sha256'] ?? null)
            || !self::positiveInteger($receipt['demand_generation'] ?? null)
            || !self::sha256($receipt['demand_id'] ?? null)
            || !self::sha256($receipt['export_id'] ?? null)
            || !self::sha256($receipt['manifest_sha256'] ?? null)
            || !self::positiveInteger($receipt['retention_deadline'] ?? null)
            || !self::sha256($receipt['snapshot_hash'] ?? null)) {
            throw new \RuntimeException('duo: cloud origin upload commit receipt identity is malformed');
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $active
     */
    private static function assertManifest(array $manifest, array $active): void {
        self::exactKeys($manifest, [
            'artifact_hash', 'chunks', 'code_revision', 'expected_production_commit',
            'export_sha256', 'export_size', 'format', 'generation',
            'manifest_sha256', 'repository_revision_hash', 'snapshot_hash',
        ], 'sealed upload manifest');
        if (($manifest['format'] ?? null) !== OriginStore::WIRE_MANIFEST_FORMAT
            || ($manifest['generation'] ?? null) !== $active['demand_generation']
            || ($manifest['expected_production_commit'] ?? null) !== $active['expected_production_commit']
            || !self::positiveInteger($manifest['export_size'] ?? null)
            || $manifest['export_size'] > OriginStore::MAX_ARTIFACT_BYTES
            || !is_array($manifest['chunks'] ?? null) || !array_is_list($manifest['chunks'])
            || $manifest['chunks'] === []) {
            throw new \RuntimeException('duo: cloud origin upload sealed manifest identity is malformed');
        }
        foreach (['artifact_hash', 'export_sha256', 'manifest_sha256', 'repository_revision_hash', 'snapshot_hash'] as $key) {
            if (!self::sha256($manifest[$key] ?? null)) {
                throw new \RuntimeException("duo: cloud origin upload sealed manifest $key is malformed");
            }
        }
        if ($manifest['code_revision'] !== null && !self::sha256($manifest['code_revision'])) {
            throw new \RuntimeException('duo: cloud origin upload sealed manifest code revision is malformed');
        }
        $offset = 0;
        $count = count($manifest['chunks']);
        foreach ($manifest['chunks'] as $position => $chunk) {
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new \RuntimeException('duo: cloud origin upload sealed manifest chunk is malformed');
            }
            self::exactKeys($chunk, ['index', 'offset', 'sha256', 'size'], 'sealed upload chunk');
            if (($chunk['index'] ?? null) !== $position || ($chunk['offset'] ?? null) !== $offset
                || !self::sha256($chunk['sha256'] ?? null)
                || !is_int($chunk['size'] ?? null) || $chunk['size'] < 1
                || $chunk['size'] > OriginStore::CHUNK_BYTES
                || ($position < $count - 1 && $chunk['size'] !== OriginStore::CHUNK_BYTES)) {
                throw new \RuntimeException('duo: cloud origin upload sealed manifest chunk sequence is malformed');
            }
            $offset += $chunk['size'];
        }
        if ($offset !== $manifest['export_size']) {
            throw new \RuntimeException('duo: cloud origin upload sealed manifest size does not verify');
        }
        $basis = $manifest;
        $claimed = $basis['manifest_sha256'];
        unset($basis['manifest_sha256']);
        if (!hash_equals($claimed, hash('sha256', Canon::encode($basis)))) {
            throw new \RuntimeException('duo: cloud origin upload sealed manifest hash does not verify');
        }
    }

    /** @param array<string,mixed> $manifest @return array<string,int> */
    private static function chunkDescriptors(array $manifest): array {
        $descriptors = [];
        foreach ($manifest['chunks'] as $chunk) {
            $sha256 = (string) $chunk['sha256'];
            if (!isset($descriptors[$sha256])) {
                $descriptors[$sha256] = (int) $chunk['index'];
            }
        }
        return $descriptors;
    }

    private static function sessionId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $expectedCommit
    ): string {
        return hash(
            'sha256',
            "duo-cloud-origin-session/v1\0$tenantId\0$siteId\0$originGeneration"
                . "\0$demandGeneration\0$demandId\0$expectedCommit"
        );
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: cloud origin $label has unexpected fields");
        }
    }

    private static function identifier(mixed $value): bool {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function commit(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $value) === 1;
    }

    private static function positiveInteger(mixed $value): bool {
        return is_int($value) && $value >= 1 && $value <= self::MAX_SAFE_INTEGER;
    }

    private static function nonNegativeInteger(mixed $value): bool {
        return is_int($value) && $value >= 0 && $value <= self::MAX_SAFE_INTEGER;
    }

    private static function assertControlPlane(): void {
        if (!defined('WP_CLI') || WP_CLI !== true
            || !defined('DUO_CONTROL_PLANE') || DUO_CONTROL_PLANE !== true) {
            throw new \RuntimeException('duo: cloud origin upload requires the isolated WP-CLI control plane');
        }
    }
}
