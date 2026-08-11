<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Recovery\RollbackControl;

/**
 * Checkpoint-only authority for one SSH scoped state-promotion window.
 *
 * This is intentionally a sibling of VerifiedRollbackProfile, not a mode on
 * it.  A scope contract excludes code/lifecycle semantics, so this profile
 * admits only the externally excluded encrypted-database checkpoint needed to
 * restore the pre-window world if the bounded apply fails before commit.  It
 * never prepares/releases code, uploads, lifecycle, or effect providers, and
 * it never offers a post-commit rollback operation.
 */
final class ScopedRollbackProfile {
    private RollbackAuthority $authority;
    private SshTransport $transport;

    public function __construct(SshTransport $transport) {
        $this->transport = $transport;
        $this->authority = new RollbackAuthority($transport);
    }

    /**
     * @param array<string,mixed> $plan
     * @param ?array<string,mixed> $status
     * @return array{automatic:bool,reason:string,status:array<string,mixed>}
     */
    public static function select(
        SshTransport $transport,
        array $plan,
        string $scopeHash,
        ?array $status = null
    ): array {
        $missing = [];
        if (!$transport->rollbackConfigured()) $missing[] = 'rollback signing key';
        if (!$transport->recoveryConfigured()) $missing[] = 'recovery executor';
        if (!$transport->checkpointConfigured()) $missing[] = 'checkpoint provider';
        if ($missing) {
            return [
                'automatic' => false,
                'reason' => 'missing ' . implode(', ', $missing),
                'status' => $status ?? [],
            ];
        }
        $status ??= RollbackAuthority::scopedStatus($transport);
        if (($status['available'] ?? false) !== true) {
            return ['automatic' => false, 'reason' => 'rollback authority runtime unavailable', 'status' => $status];
        }
        if (($status['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                'duo rollback: scoped authority status is invalid: '
                . trim((string) ($status['error'] ?? 'verification failed'))
            );
        }
        $resuming = false;
        if (($status['active'] ?? false) === true) {
            if (($status['receipt_format'] ?? null) !== RollbackControl::SCOPED_PROMOTION_RECEIPT_FORMAT) {
                if (empty($status['terminal'])) {
                    return [
                        'automatic' => false,
                        'reason' => 'a nonterminal ordinary rollback generation is active',
                        'status' => $status,
                    ];
                }
            } elseif (empty($status['terminal'])) {
                $resuming = true;
            } else {
                $terminal = array_key_exists('exclusion_state', $status)
                    ? $status
                    : RollbackAuthority::status($transport);
                if (($terminal['exclusion_state'] ?? null) === 'held') {
                    $resuming = true;
                    $status = $terminal;
                } elseif (($terminal['exclusion_state'] ?? null) !== 'released') {
                    throw new \RuntimeException('duo rollback: scoped terminal exclusion has an unknown state');
                }
            }
        }
        if ($resuming) {
            try {
                $intent = self::resumeIntent($plan, 'scope-select', $scopeHash);
                if (!hash_equals((string) $intent['artifact_hash'], (string) ($status['artifact_hash'] ?? ''))
                    || !hash_equals((string) $intent['scope_hash'], (string) ($status['scope_hash'] ?? ''))) {
                    return [
                        'automatic' => false,
                        'reason' => 'active scoped receipt does not match the requested scope/artifact',
                        'status' => $status,
                    ];
                }
            } catch (\Throwable $e) {
                return ['automatic' => false, 'reason' => $e->getMessage(), 'status' => $status];
            }
            return [
                'automatic' => true,
                'reason' => 'active checkpoint-only scoped promotion is resumable',
                'status' => $status,
            ];
        }
        if (!$transport->verifiedRollbackConfigured()) {
            return [
                'automatic' => false,
                'reason' => 'missing verified_rollback policy',
                'status' => $status,
            ];
        }
        try {
            self::claimFields(
                $plan,
                (array) $transport->verifiedRollbackConfig(),
                'scope-select',
                $scopeHash,
                '2026-01-01T00:00:00Z'
            );
        } catch (\Throwable $e) {
            return [
                'automatic' => false,
                'reason' => $e->getMessage(),
                'status' => $status,
            ];
        }
        return [
            'automatic' => true,
            'reason' => 'checkpoint-only scoped promotion capabilities are ready',
            'status' => $status,
        ];
    }

    /**
     * Build only the caller-owned input for RollbackAuthority::claimScoped().
     * `retention_seconds` is intentionally private claim input, not a receipt
     * field: the authority derives the immutable absolute deadline from the
     * durable exclusion reservation so an interrupted fresh process retries
     * byte-for-byte rather than using its current wall clock.
     *
     * @param array<string,mixed> $plan
     * @param array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} $policy
     * @return array<string,mixed>
     */
    public static function claimFields(
        array $plan,
        array $policy,
        string $owner,
        string $scopeHash,
        string $createdAt
    ): array {
        self::assertHash($scopeHash, 'scope hash');
        self::assertActor($owner, 'scoped promotion owner');
        self::timestampValue($createdAt, 'scoped claim timestamp');
        $artifact = (string) ($plan['artifact_hash'] ?? '');
        self::assertHash($artifact, 'scoped compiled artifact hash');
        $scope = $plan['scope'] ?? null;
        if (!is_array($scope)
            || ($scope['format'] ?? null) !== 'duo-scope-contract/v1'
            || !hash_equals($scopeHash, (string) ($scope['scope_hash'] ?? ''))
            || !hash_equals($artifact, (string) ($scope['source_artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo rollback: scoped plan does not bind the exact scope/artifact');
        }
        if (($plan['format'] ?? null) !== 'duo-scoped-plan/v1') {
            throw new \RuntimeException('duo rollback: checkpoint-only profile requires a scoped target plan');
        }
        $target = $plan['target'] ?? null;
        if (!is_array($target)) {
            throw new \RuntimeException('duo rollback: scoped plan has no target root witnesses');
        }
        foreach ([
            'selected_before_root', 'protected_out_of_scope_root', 'ledger_map_root',
            'protected_ledger_map_root', 'selected_ledger_map_root', 'target_observation_hash',
        ] as $key) {
            self::assertHash((string) ($target[$key] ?? ''), "scoped target $key");
        }
        $surfaces = $plan['selected_surfaces'] ?? null;
        $actions = $plan['selected_actions'] ?? null;
        if (!is_array($surfaces) || !array_is_list($surfaces)
            || !is_array($actions) || !array_is_list($actions)) {
            throw new \RuntimeException('duo rollback: scoped plan has no canonical selected work evidence');
        }
        // The first checkpoint-only profile has no scoped inverse protocol for
        // effects.  A selected action is thus a typed refusal, never a silent
        // route through a configured ordinary effect provider.
        if ($actions !== []) {
            throw new \RuntimeException('duo rollback: checkpoint-only scoped promotion refuses selected actions/effects');
        }
        $adapters = $plan['resolved_adapters'] ?? null;
        if (!is_array($adapters) || !array_is_list($adapters)) {
            throw new \RuntimeException('duo rollback: scoped plan has no resolved adapter identity');
        }
        $ttl = $policy['claim_ttl_seconds'] ?? null;
        $retention = $policy['retention_seconds'] ?? null;
        $key = $policy['encryption_key_id'] ?? null;
        if (!is_int($ttl) || $ttl < 30 || $ttl > 3600
            || !is_int($retention) || $retention < 60 || $retention > 31536000
            || !is_string($key) || $key === '') {
            throw new \RuntimeException('duo rollback: checkpoint-only profile policy is incomplete');
        }
        self::assertActor($key, 'scoped encryption key id');
        $resources = [
            'format' => 'duo-scoped-promotion-resources/v1',
            'scope' => [
                'scope_hash' => $scopeHash,
                'source_artifact_hash' => $artifact,
            ],
            'selected_actions' => $actions,
            'selected_surfaces' => $surfaces,
            'target' => [
                'ledger_map_root' => (string) $target['ledger_map_root'],
                'protected_ledger_map_root' => (string) $target['protected_ledger_map_root'],
                'protected_out_of_scope_root' => (string) $target['protected_out_of_scope_root'],
                'selected_before_root' => (string) $target['selected_before_root'],
                'selected_ledger_map_root' => (string) $target['selected_ledger_map_root'],
                'target_observation_hash' => (string) $target['target_observation_hash'],
            ],
        ];
        return [
            'adapter_versions_sha256' => hash('sha256', RollbackControl::canonical($adapters)),
            'artifact_hash' => $artifact,
            'claim_ttl_seconds' => $ttl,
            'encryption_key_id' => $key,
            'owner' => $owner,
            'resources_inventory_sha256' => hash('sha256', RollbackControl::canonical($resources)),
            'retention_seconds' => $retention,
            'scope_hash' => $scopeHash,
        ];
    }

    /**
     * Claim or resume the exact scoped generation while its exclusion remains
     * held. A verified released terminal receipt instead starts the next
     * generation.
     *
     * @param array<string,mixed> $plan
     * @return array{receipt:array<string,mixed>,receipt_payload_sha256:string,status:array<string,mixed>}
     */
    public function claim(
        array $plan,
        string $scopeHash,
        string $owner,
        string $claimant,
        ?string $timestamp = null
    ): array {
        $resume = false;
        $current = RollbackAuthority::authorityStatus($this->transport);
        if (($current['available'] ?? false) === true
            && ($current['ok'] ?? false) === true
            && ($current['active'] ?? false) === true
            && ($current['receipt_format'] ?? null) === RollbackControl::SCOPED_PROMOTION_RECEIPT_FORMAT) {
            if (empty($current['terminal'])) {
                $resume = true;
            } else {
                // Raw authority status deliberately does not disclose the
                // opaque external exclusion.  Only a decorated terminal
                // status can distinguish a lost release response (resume)
                // from a verified released terminal record (new generation).
                $terminal = RollbackAuthority::status($this->transport);
                if (($terminal['exclusion_state'] ?? null) === 'held') {
                    $resume = true;
                } elseif (($terminal['exclusion_state'] ?? null) !== 'released') {
                    throw new \RuntimeException('duo rollback: scoped terminal exclusion has an unknown state');
                }
            }
        }
        if ($resume) {
            $claim = $this->authority->claimScoped(
                self::resumeIntent($plan, $owner, $scopeHash),
                $claimant,
                $timestamp
            );
        } else {
            $policy = $this->transport->verifiedRollbackConfig();
            if ($policy === null) {
                throw new \RuntimeException('duo rollback: verified_rollback policy is not configured');
            }
            $now = $timestamp ?? self::timestamp();
            $claim = $this->authority->claimScoped(
                self::claimFields($plan, $policy, $owner, $scopeHash, $now),
                $claimant,
                $now
            );
        }
        $payloadHash = hash('sha256', RollbackControl::canonical($claim['receipt']));
        return $claim + ['receipt_payload_sha256' => $payloadHash];
    }

    /** @return array<string,mixed> */
    public function startPromotion(): array {
        $status = $this->status();
        if (in_array((string) $status['state'], ['promoting', 'verifying_new', 'committed'], true)) {
            return $status;
        }
        return $this->transition('promoting', 'scoped_promotion_start', ['prepared']);
    }

    /** @return array<string,mixed> */
    public function keepalive(): array {
        return $this->authority->keepaliveScoped();
    }

    /**
     * Bind one exact terminal scoped-apply receipt to the external hash chain
     * before the promotion can commit.  The receipt is validation evidence,
     * not a recovery preimage; automatic rollback restores the encrypted
     * checkpoint only while this authority remains nonterminal.
     *
     * @param array<string,mixed> $terminalReceipt
     * @return array<string,mixed>
     */
    public function recordScopedApply(array $terminalReceipt): array {
        $terminal = self::validateTerminalReceipt($terminalReceipt);
        $status = $this->status();
        if (in_array((string) $status['state'], ['verifying_new', 'committed'], true)) {
            $this->assertCompletedScopedApply($terminal);
            return $status;
        }
        if ((string) $status['state'] !== 'promoting') {
            throw new \RuntimeException('duo rollback: scoped apply receipt can be recorded only while promoting');
        }
        $input = self::scopedApplyInput($status, $terminal);
        return $this->recordHashOnlyOperation(
            'promoting',
            'scoped_apply',
            $input,
            (string) $terminal['terminal_hash']
        );
    }

    /**
     * Seal the signed successful result before the target handoff is retired.
     * The completed scoped_apply event is required by both the profile and
     * target control runtime, so a caller cannot commit merely because it has
     * a terminal-looking local result.
     *
     * @return array<string,mixed>
     */
    public function sealCommit(): array {
        $status = $this->status();
        $this->assertCompletedScopedApply();
        if ((string) $status['state'] === 'promoting') {
            $status = $this->transition('verifying_new', 'scoped_fresh_verification', ['promoting']);
        }
        if ((string) $status['state'] === 'verifying_new') {
            $status = $this->transition('committed', 'scoped_committed_verified', ['verifying_new']);
        }
        if ((string) $status['state'] !== 'committed') {
            throw new \RuntimeException('duo rollback: scoped commit has an incompatible authority state');
        }
        return $status;
    }

    /**
     * Reopen the independent exclusion only after a terminal signed state.
     * Exact retry is safe when a provider accepted release but its response
     * was lost: releaseScopedExclusion() returns an idempotent acknowledgement.
     *
     * @return array<string,mixed>
     */
    public function release(): array {
        $this->statusTerminal();
        $this->authority->releaseScopedExclusion();
        return $this->statusTerminal();
    }

    /**
     * Backwards-compatible composed successful finish.  Orchestrated host
     * routing should normally call sealCommit(), complete target handoff, and
     * release() as three separately retryable boundaries.
     *
     * @return array<string,mixed>
     */
    public function commit(): array {
        $this->sealCommit();
        return $this->release();
    }

    /**
     * Automatically restore the pre-window whole database only before a
     * scoped promotion commits.  This is an exclusive-window failure path,
     * not a public scoped rollback capability after commit.
     *
     * @return array<string,mixed>
     */
    public function rollback(): array {
        $status = $this->status();
        if ((string) $status['state'] === 'committed') {
            throw new \RuntimeException('duo rollback: committed scoped promotion has no automatic rollback authority');
        }
        if ((string) $status['state'] === 'rolled_back') {
            return $this->release();
        }
        if (in_array((string) $status['state'], ['prepared', 'promoting', 'verifying_new'], true)) {
            $status = $this->transition('rollback_pending', 'scoped_promotion_failed', [
                'prepared', 'promoting', 'verifying_new',
            ]);
        }
        if ((string) $status['state'] === 'rollback_pending') {
            $status = $this->transition('rolling_back', 'scoped_rollback_start', ['rollback_pending']);
        }
        $checkpoint = (string) ($status['checkpoint_sha256'] ?? '');
        self::assertHash($checkpoint, 'scoped checkpoint hash');
        if ((string) $status['state'] === 'rolling_back') {
            $this->runCheckpointOperation('rolling_back', 'database_restore', [
                'checkpoint_sha256' => $checkpoint,
                'operation' => 'database_restore',
            ]);
            $status = $this->transition('verifying_prior', 'scoped_verifying_prior', ['rolling_back']);
        }
        if ((string) $status['state'] === 'verifying_prior') {
            $this->runCheckpointOperation('verifying_prior', 'prior_verify', [
                'checkpoint_sha256' => $checkpoint,
                'operation' => 'prior_verify',
            ]);
            $status = $this->transition('rolled_back', 'scoped_rolled_back_verified', ['verifying_prior']);
        }
        if ((string) $status['state'] !== 'rolled_back') {
            throw new \RuntimeException('duo rollback: scoped rollback has an incompatible authority state');
        }
        return $this->release();
    }

    /** @return array<string,mixed> */
    public function status(): array {
        $status = RollbackAuthority::scopedStatus($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true
            || ($status['receipt_format'] ?? null) !== RollbackControl::SCOPED_PROMOTION_RECEIPT_FORMAT) {
            throw new \RuntimeException('duo rollback: active scoped promotion receipt is unavailable or invalid');
        }
        return $status;
    }

    /** @return array<string,mixed> */
    private function statusTerminal(): array {
        $status = $this->status();
        if (empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: expected terminal scoped authority');
        }
        return $status;
    }

    /** @param list<string> $from @return array<string,mixed> */
    private function transition(string $next, string $operation, array $from): array {
        $status = $this->status();
        if ((string) $status['state'] === $next) {
            return $status;
        }
        if (!in_array((string) $status['state'], $from, true)) {
            throw new \RuntimeException(
                "duo rollback: cannot transition scoped authority from {$status['state']} to $next"
            );
        }
        return $this->authority->appendScoped(
            $next,
            'state_transition',
            $operation,
            1,
            (string) $status['claimant'],
            hash('sha256', $operation . ':input'),
            hash('sha256', $operation . ':result')
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function recordHashOnlyOperation(string $state, string $operation, array $input, string $resultHash): array {
        self::assertHash($resultHash, "$operation result hash");
        $inputHash = hash('sha256', RollbackControl::canonical($input) . "\n");
        $evidence = RollbackAuthority::scopedEvidence($this->transport);
        $status = (array) $evidence['status'];
        if ((string) ($status['state'] ?? '') !== $state) {
            throw new \RuntimeException("duo rollback: $operation cannot resume outside $state");
        }
        $completed = (array) ($evidence['completed_operations'] ?? []);
        $done = $completed[$operation] ?? null;
        if (is_array($done)) {
            if ((int) ($done['attempt'] ?? 0) !== 1
                || !hash_equals($inputHash, (string) ($done['input_sha256'] ?? ''))
                || !hash_equals($resultHash, (string) ($done['result_sha256'] ?? ''))) {
                throw new \RuntimeException("duo rollback: completed $operation does not match this exact scoped evidence");
            }
            return $status;
        }
        $open = (array) ($evidence['open_operations'] ?? []);
        $pending = $open[$operation . '#1'] ?? null;
        if (is_array($pending)) {
            if (!hash_equals($inputHash, (string) ($pending['input_sha256'] ?? ''))) {
                throw new \RuntimeException("duo rollback: prepared $operation does not match this exact scoped evidence");
            }
        } else {
            $this->authority->prepareScopedOperation($state, $operation, 1, $input);
        }
        $fresh = $this->status();
        return $this->authority->appendScoped(
            $state,
            'completed',
            $operation,
            1,
            (string) $fresh['claimant'],
            $inputHash,
            $resultHash
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function runCheckpointOperation(string $state, string $adapter, array $input): array {
        $inputHash = hash('sha256', RollbackControl::canonical($input) . "\n");
        $evidence = RollbackAuthority::scopedEvidence($this->transport);
        $status = (array) $evidence['status'];
        if ((string) ($status['state'] ?? '') !== $state) {
            throw new \RuntimeException("duo rollback: $adapter cannot resume outside $state");
        }
        $completed = (array) ($evidence['completed_operations'] ?? []);
        $done = $completed[$adapter] ?? null;
        if (is_array($done)) {
            if ((int) ($done['attempt'] ?? 0) !== 1
                || !hash_equals($inputHash, (string) ($done['input_sha256'] ?? ''))
                || !preg_match('/^[a-f0-9]{64}$/', (string) ($done['result_sha256'] ?? ''))) {
                throw new \RuntimeException("duo rollback: completed $adapter does not match this exact checkpoint operation");
            }
            return $status;
        }
        $open = (array) ($evidence['open_operations'] ?? []);
        $pending = $open[$adapter . '#1'] ?? null;
        if (is_array($pending)) {
            if (!hash_equals($inputHash, (string) ($pending['input_sha256'] ?? ''))) {
                throw new \RuntimeException("duo rollback: prepared $adapter does not match this exact checkpoint operation");
            }
        } else {
            $this->authority->prepareScopedOperation($state, $adapter, 1, $input);
        }
        $execution = $this->authority->executeScopedOperation($adapter, 1, $input);
        return $this->authority->completeScopedOperation($state, $adapter, 1, $input, $execution);
    }

    /**
     * Confirm the signed chain contains the exact current-epoch completion
     * minted by recordScopedApply().  The terminal receipt itself is bound by
     * that operation's input/result hashes; only the target-verified event
     * maps decide whether a subsequent commit is admissible.
     */
    private function assertCompletedScopedApply(?array $terminal = null): void {
        $evidence = RollbackAuthority::scopedEvidence($this->transport);
        $status = (array) ($evidence['status'] ?? []);
        $state = (string) ($status['state'] ?? '');
        if (!in_array($state, ['promoting', 'verifying_new', 'committed'], true)) {
            throw new \RuntimeException('duo rollback: scoped commit has no promotable authority state');
        }
        $open = (array) ($evidence['open_operations'] ?? []);
        if (isset($open['scoped_apply#1'])) {
            throw new \RuntimeException('duo rollback: scoped commit requires completed, not open, scoped_apply evidence');
        }
        $completed = (array) ($evidence['completed_operations'] ?? []);
        $done = $completed['scoped_apply'] ?? null;
        if (!is_array($done)
            || (string) ($done['operation_id'] ?? '') !== 'scoped_apply'
            || (string) ($done['operation_status'] ?? '') !== 'completed'
            || (string) ($done['state'] ?? '') !== 'promoting'
            || (int) ($done['attempt'] ?? 0) !== 1
            || (int) ($done['claim_epoch'] ?? 0) !== (int) ($status['claim_epoch'] ?? 0)
            || (string) ($done['claimant'] ?? '') !== (string) ($status['claimant'] ?? '')
            || preg_match('/^[a-f0-9]{64}$/', (string) ($done['input_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($done['result_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('duo rollback: scoped commit requires exact completed scoped_apply evidence');
        }
        $history = (array) ($evidence['completed_operation_history'] ?? []);
        $historical = $history['scoped_apply'] ?? null;
        if (!is_array($historical)
            || !hash_equals(RollbackControl::canonical($done), RollbackControl::canonical($historical))) {
            throw new \RuntimeException('duo rollback: scoped apply completion history is inconsistent');
        }
        if ($terminal !== null) {
            $input = self::scopedApplyInput($status, $terminal);
            $expectedInput = hash('sha256', RollbackControl::canonical($input) . "\n");
            if (!hash_equals($expectedInput, (string) $done['input_sha256'])
                || !hash_equals((string) $terminal['terminal_hash'], (string) $done['result_sha256'])) {
                throw new \RuntimeException('duo rollback: completed scoped_apply does not bind this terminal receipt');
            }
        }
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function resumeIntent(array $plan, string $owner, string $scopeHash): array {
        self::assertHash($scopeHash, 'scope hash');
        self::assertActor($owner, 'scoped promotion owner');
        $artifact = (string) ($plan['artifact_hash'] ?? '');
        self::assertHash($artifact, 'scoped compiled artifact hash');
        return [
            'artifact_hash' => $artifact,
            'owner' => $owner,
            'scope_hash' => $scopeHash,
        ];
    }

    /** @param array<string,mixed> $status @param array<string,mixed> $terminal @return array<string,mixed> */
    private static function scopedApplyInput(array $status, array $terminal): array {
        return [
            'artifact_hash' => (string) $status['artifact_hash'],
            'authority_hash' => (string) $terminal['authority_hash'],
            'format' => 'duo-scoped-promotion-operation/v1',
            'lease_hash' => (string) $terminal['lease_hash'],
            'scope_hash' => (string) $status['scope_hash'],
            'terminal_hash' => (string) $terminal['terminal_hash'],
            'terminal_receipt_sha256' => hash('sha256', RollbackControl::canonical($terminal)),
        ];
    }

    /** @param array<string,mixed> $terminal @return array<string,mixed> */
    private static function validateTerminalReceipt(array $terminal): array {
        $expected = [
            'authority_hash', 'convergence_hash', 'intents_hash', 'lease_hash', 'phase',
            'protected_ledger_map_hash', 'receipts_hash', 'selected_ledger_map_hash',
            'session_id', 'terminal_hash',
        ];
        $keys = array_keys($terminal);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($terminal['phase'] ?? null) !== 'complete') {
            throw new \RuntimeException('duo rollback: scoped apply terminal receipt is malformed');
        }
        foreach ([
            'authority_hash', 'convergence_hash', 'intents_hash', 'lease_hash',
            'protected_ledger_map_hash', 'receipts_hash', 'selected_ledger_map_hash', 'terminal_hash',
        ] as $key) {
            self::assertHash((string) ($terminal[$key] ?? ''), "scoped terminal $key");
        }
        self::assertActor((string) ($terminal['session_id'] ?? ''), 'scoped terminal session id');
        $withoutHash = $terminal;
        unset($withoutHash['terminal_hash']);
        if (!hash_equals(
            hash('sha256', \Duo\Canon::encode($withoutHash)),
            (string) $terminal['terminal_hash']
        )) {
            throw new \RuntimeException('duo rollback: scoped apply terminal receipt hash does not verify');
        }
        return $terminal;
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \RuntimeException("duo rollback: $label must be a sha256 digest");
        }
    }

    private static function assertActor(string $value, string $label): void {
        if (strlen($value) < 1 || strlen($value) > 200
            || preg_match('/^[A-Za-z0-9._:@+\\/-]+$/', $value) !== 1) {
            throw new \RuntimeException("duo rollback: $label is malformed");
        }
    }

    private static function timestampValue(string $value, string $label): int {
        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s\\Z',
            $value,
            new \DateTimeZone('UTC')
        );
        if (!$time || $time->format('Y-m-d\\TH:i:s\\Z') !== $value) {
            throw new \RuntimeException("duo rollback: $label must be canonical UTC seconds");
        }
        return $time->getTimestamp();
    }

    private static function timestamp(): string {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }
}
