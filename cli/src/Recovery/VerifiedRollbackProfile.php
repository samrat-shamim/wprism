<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

use WPrism\Recovery\CanonicalJson;
use WPrism\Recovery\RollbackControl;

/**
 * Plan-to-claim and signed-state orchestration for production promotion on
 * any transport that carries the rollback authority (`RecoveryTransport`).
 *
 * The profile is selected only from registry/runtime capabilities. It never
 * examines a target filesystem to guess whether a provider might exist. The
 * same claim builder is used by the product CLI and the live crash matrix so
 * the certified inventories are the exact compiled plan inventories.
 */
final class VerifiedRollbackProfile {
    /** @var list<string> */
    private const RECOVERY_OPERATIONS = [
        'effects_inverse',
        'storage_restore',
        'code_restore',
        'database_restore',
    ];

    /** @var list<string> */
    private const RECOVERY_STEP_ORDER = [
        'promotion_failed',
        'rollback_start',
        'effects_inverse',
        'storage_restore',
        'code_restore',
        'database_restore',
        'verifying_prior',
        'prior_verify',
        'rolled_back_verified',
        'exclusion_release',
    ];

    /** @var list<string> */
    private const RECOVERY_START_STATES = [
        'prepared',
        'promoting',
        'verifying_new',
        'rollback_pending',
        'rolling_back',
        'verifying_prior',
    ];

    private RollbackAuthority $authority;
    private RecoveryTransport $transport;

    public function __construct(RecoveryTransport $transport) {
        $this->transport = $transport;
        $this->authority = new RollbackAuthority($transport);
    }

    /**
     * @param array<string,mixed> $plan
     * @param ?array<string,mixed> $status
     * @return array{automatic:bool,reason:string,status:array<string,mixed>}
     */
    public static function select(RecoveryTransport $transport, array $plan, ?array $status = null): array {
        $missing = [];
        if (!$transport->rollbackConfigured()) $missing[] = 'rollback signing key';
        if (!$transport->recoveryConfigured()) $missing[] = 'recovery executor';
        if (!$transport->checkpointConfigured()) $missing[] = 'checkpoint provider';
        if (!$transport->codeReleaseConfigured()) $missing[] = 'code-release provider';
        if (!$transport->uploadProviderConfigured()) $missing[] = 'upload provider';
        if (!$transport->effectProviderConfigured()) $missing[] = 'effect provider';
        if (!$transport->verifiedRollbackConfigured()) $missing[] = 'verified_rollback policy';
        if ($missing) {
            return [
                'automatic' => false,
                'reason' => 'missing ' . implode(', ', $missing),
                'status' => $status ?? [],
            ];
        }
        $code = $plan['code'] ?? null;
        if (!is_array($code)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($code['code_revision'] ?? '')) !== 1) {
            return [
                'automatic' => false,
                'reason' => 'compiled plan has no code release identity',
                'status' => $status ?? [],
            ];
        }
        foreach (['uploads_inventory', 'effects_inventory'] as $inventory) {
            if (!is_array($plan[$inventory] ?? null) || !array_is_list($plan[$inventory])) {
                return [
                    'automatic' => false,
                    'reason' => "compiled plan has no canonical $inventory",
                    'status' => $status ?? [],
                ];
            }
        }
        $status ??= RollbackAuthority::status($transport);
        if (($status['available'] ?? false) !== true) {
            return ['automatic' => false, 'reason' => 'rollback authority runtime unavailable', 'status' => $status];
        }
        if (($status['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                'wprism rollback: authority status is invalid: '
                . trim((string) ($status['error'] ?? 'verification failed'))
            );
        }
        if (($status['recovery_ready'] ?? false) !== true) {
            throw new \RuntimeException('wprism rollback: configured recovery providers did not pass preflight');
        }
        return ['automatic' => true, 'reason' => 'all verified rollback capabilities are ready', 'status' => $status];
    }

    /**
     * Build the controller-owned portion of one immutable receipt claim.
     * The product path passes the complete compiled code inventory to the
     * target runtime. A caller-supplied descriptor hash remains available only
     * for the legacy v1 controller contract, where the controller already knew
     * the provider-owned generation/release descriptor.
     *
     * @param array<string,mixed> $plan
     * @param array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} $policy
     * @param ?array{desired_code_revision?:string,desired_descriptor_sha256?:string} $codeReleaseIdentity
     * @return array<string,mixed>
     */
    public static function claimFields(
        array $plan,
        array $policy,
        string $owner,
        string $createdAt,
        ?array $codeReleaseIdentity = null,
        bool $allowDeletes = false
    ): array {
        $artifact = (string) ($plan['artifact_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $artifact) !== 1) {
            throw new \RuntimeException('wprism rollback: automatic profile needs the compiled artifact hash');
        }
        $uploads = $plan['uploads_inventory'] ?? null;
        $effects = $plan['effects_inventory'] ?? null;
        if (!is_array($uploads) || !array_is_list($uploads)
            || !is_array($effects) || !array_is_list($effects)) {
            throw new \RuntimeException('wprism rollback: automatic profile needs compiled plan inventories');
        }
        $code = $plan['code'] ?? null;
        if (!is_array($code)) {
            throw new \RuntimeException('wprism rollback: automatic profile needs a compiled code descriptor');
        }
        $revision = (string) ($codeReleaseIdentity['desired_code_revision'] ?? $code['code_revision'] ?? '');
        $descriptorHash = $codeReleaseIdentity['desired_descriptor_sha256'] ?? null;
        foreach (['desired code revision' => $revision] as $label => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new \RuntimeException("wprism rollback: automatic profile $label is not a sha256 digest");
            }
        }
        if ($descriptorHash !== null
            && (!is_string($descriptorHash) || preg_match('/^[a-f0-9]{64}$/', $descriptorHash) !== 1)) {
            throw new \RuntimeException('wprism rollback: automatic profile desired descriptor is not a sha256 digest');
        }
        $created = self::timeValue($createdAt);
        $retention = $policy['retention_seconds'] ?? null;
        $ttl = $policy['claim_ttl_seconds'] ?? null;
        $key = $policy['encryption_key_id'] ?? null;
        if (!is_int($retention) || $retention < 60
            || !is_int($ttl) || $ttl < 30 || $ttl > 3600
            || !is_string($key) || $key === '') {
            throw new \RuntimeException('wprism rollback: automatic profile policy is incomplete');
        }
        $resources = [
            'code' => $code,
            'effects_inventory' => $effects,
            'uploads_inventory' => $uploads,
        ];
        $fields = [
            'adapter_versions_sha256' => hash(
                'sha256',
                RollbackControl::canonical((array) ($plan['resolved_adapters'] ?? []))
            ),
            'allow_deletes' => $allowDeletes,
            'artifact_hash' => $artifact,
            'claim_ttl_seconds' => $ttl,
            'desired_code_revision' => $revision,
            'effect_inventory' => $effects,
            'encryption_key_id' => $key,
            'owner' => $owner,
            'resources_inventory_sha256' => hash('sha256', RollbackControl::canonical($resources)),
            'retention_until' => gmdate('Y-m-d\TH:i:s\Z', $created + $retention),
            'upload_inventory' => $uploads,
        ];
        if ($descriptorHash !== null) {
            $fields['desired_descriptor_sha256'] = $descriptorHash;
        } else {
            $fields['desired_code_inventory'] = $code;
        }
        return $fields;
    }

    /** @param array<string,mixed> $plan @return array{receipt:array<string,mixed>,status:array<string,mixed>} */
    public function claim(
        array $plan,
        string $owner,
        string $claimant,
        ?string $timestamp = null,
        bool $allowDeletes = false
    ): array {
        $policy = $this->transport->verifiedRollbackConfig();
        if ($policy === null) {
            throw new \RuntimeException('wprism rollback: verified_rollback policy is not configured');
        }
        if ($timestamp === null) {
            $status = RollbackAuthority::status($this->transport);
            $reservation = $status['exclusion_reservation'] ?? null;
            $timestamp = ($status['active'] ?? false) !== true
                && ($status['exclusion_state'] ?? '') === 'held'
                && is_array($reservation)
                && is_string($reservation['reserved_at'] ?? null)
                    ? $reservation['reserved_at']
                    : self::timestamp();
        }
        return $this->authority->claim(
            self::claimFields($plan, $policy, $owner, $timestamp, null, $allowDeletes),
            $claimant,
            $timestamp
        );
    }

    public function startPromotion(): array {
        return $this->transition('promoting', 'promotion_start');
    }

    public function keepalive(): array {
        return $this->authority->keepalive();
    }

    /** Select and independently verify the prepared desired code release. */
    public function selectCode(): array {
        $status = $this->status();
        $code = $status['code_release'] ?? null;
        if (!is_array($code)) throw new \RuntimeException('wprism rollback: prepared receipt has no code release evidence');
        return $this->authority->runOperation('promoting', 'code_select', 1, [
            'artifact_hash' => (string) $status['artifact_hash'],
            'code_release_metadata_sha256' => (string) ($code['metadata_sha256'] ?? ''),
            'expected_from_pointer_sha256' => (string) ($code['prior_pointer_sha256'] ?? ''),
            'format' => 'wprism-code-release-operation/v1',
            'generation' => (int) $status['generation'],
            'operation' => 'select_desired',
            'owner' => (string) $status['owner'],
            'receipt_id' => (string) $status['receipt_id'],
            'target_id' => (string) $status['target_id'],
        ]);
    }

    /** Publish and verify only the compiled upload inventory. */
    public function applyUploads(): array {
        $status = $this->status();
        $uploads = $status['uploads'] ?? null;
        if (!is_array($uploads)) throw new \RuntimeException('wprism rollback: prepared receipt has no upload evidence');
        return $this->authority->runOperation(
            'promoting',
            'storage_apply',
            1,
            self::uploadInput($status, 'apply_desired')
        );
    }

    /** Admit the existing fresh apply verifier, publish committed, then reopen traffic. */
    public function commit(): array {
        $this->transition('verifying_new', 'fresh_new_verification');
        $status = $this->transition('committed', 'committed_verified');
        $this->authority->releaseExclusion();
        return $status;
    }

    /**
     * Converge a failed promotion to the prior world. Operations are included
     * only after the corresponding forward boundary could have mutated; the
     * encrypted database checkpoint is always restored because promotion-begin
     * itself writes the database lease.
     */
    public function rollback(bool $effectsTouched, bool $uploadsApplied, bool $codeSelected): array {
        $this->transition('rollback_pending', 'promotion_failed');
        $this->transition('rolling_back', 'rollback_start');
        if ($effectsTouched) {
            $status = $this->status();
            $effect = $status['effects'] ?? null;
            if (!is_array($effect)) throw new \RuntimeException('wprism rollback: receipt has no effect evidence');
            $this->authority->runOperation('rolling_back', 'effects_inverse', 1, [
                'artifact_hash' => (string) $status['artifact_hash'],
                'effects_inventory_sha256' => (string) ($effect['effects_inventory_sha256'] ?? ''),
                'format' => 'wprism-effect-operation/v1',
                'generation' => (int) $status['generation'],
                'lifecycle_receipts_sha256' => (string) ($effect['metadata_sha256'] ?? ''),
                'operation' => 'restore_prior',
                'owner' => (string) $status['owner'],
                'prior_evidence_sha256' => (string) ($effect['prior_evidence_sha256'] ?? ''),
                'receipt_id' => (string) $status['receipt_id'],
                'target_id' => (string) $status['target_id'],
            ]);
        }
        if ($uploadsApplied) {
            $status = $this->status();
            $this->authority->runOperation(
                'rolling_back',
                'storage_restore',
                1,
                self::uploadInput($status, 'restore_prior')
            );
        }
        if ($codeSelected) {
            $status = $this->status();
            $code = $status['code_release'] ?? null;
            if (!is_array($code)) throw new \RuntimeException('wprism rollback: receipt has no code release evidence');
            $this->authority->runOperation('rolling_back', 'code_restore', 1, [
                'artifact_hash' => (string) $status['artifact_hash'],
                'code_release_metadata_sha256' => (string) ($code['metadata_sha256'] ?? ''),
                'expected_from_pointer_sha256' => (string) ($code['desired_pointer_sha256'] ?? ''),
                'format' => 'wprism-code-release-operation/v1',
                'generation' => (int) $status['generation'],
                'operation' => 'restore_prior',
                'owner' => (string) $status['owner'],
                'receipt_id' => (string) $status['receipt_id'],
                'target_id' => (string) $status['target_id'],
            ]);
        }
        $status = $this->status();
        $checkpoint = (string) ($status['checkpoint_sha256'] ?? '');
        $this->authority->runOperation('rolling_back', 'database_restore', 1, [
            'checkpoint_sha256' => $checkpoint,
            'operation' => 'database_restore',
        ]);
        $this->transition('verifying_prior', 'verifying_prior');
        $this->authority->runOperation('verifying_prior', 'prior_verify', 1, [
            'checkpoint_sha256' => $checkpoint,
            'operation' => 'prior_verify',
        ]);
        $status = $this->transition('rolled_back', 'rolled_back_verified');
        $this->authority->releaseExclusion();
        return $status;
    }

    /**
     * Resume the exact frozen full-receipt generation under already-consumed
     * external authority. Every provider operation is recovered from the
     * target-verified open/completed maps: an exact completion is skipped, an
     * exact prepared input is executed and completed, and absence alone may
     * create the next prepared operation. No later state is treated as proof
     * that an omitted resource restore happened.
     *
     * @param array<string,mixed> $frozenTarget RecoveryPlan::target
     * @return array{audit:array<string,mixed>,evidence:array<string,mixed>,status:array<string,mixed>,steps:list<array<string,mixed>>,verification_sha256:string}
     */
    public function recoverAuthorized(array $frozenTarget): array {
        $view = $this->recoveryView($frozenTarget, true);
        $this->assertExecutionReadiness($view);
        self::assertResumableView($view);
        $steps = [];

        if (in_array((string) $view['status']['state'], ['prepared', 'promoting', 'verifying_new'], true)) {
            $view = $this->recoveryTransition(
                $view,
                $frozenTarget,
                'rollback_pending',
                'promotion_failed',
                ['prepared', 'promoting', 'verifying_new']
            );
        }
        $steps['promotion_failed'] = self::transitionStep('promotion_failed');

        if ((string) $view['status']['state'] === 'rollback_pending') {
            $view = $this->recoveryTransition(
                $view,
                $frozenTarget,
                'rolling_back',
                'rollback_start',
                ['rollback_pending']
            );
        }
        if (!in_array((string) $view['status']['state'], ['rolling_back', 'verifying_prior', 'rolled_back'], true)) {
            throw new \RuntimeException('wprism rollback: authorized recovery did not reach rolling_back');
        }
        $steps['rollback_start'] = self::transitionStep('rollback_start');

        foreach (self::RECOVERY_OPERATIONS as $operation) {
            [$view, $event] = $this->recoverOperation($view, $frozenTarget, $operation, 'rolling_back');
            $steps[$operation] = self::operationStep($operation, $event);
        }

        if ((string) $view['status']['state'] === 'rolling_back') {
            $view = $this->recoveryTransition(
                $view,
                $frozenTarget,
                'verifying_prior',
                'verifying_prior',
                ['rolling_back']
            );
        }
        if (!in_array((string) $view['status']['state'], ['verifying_prior', 'rolled_back'], true)) {
            throw new \RuntimeException('wprism rollback: authorized recovery did not reach verifying_prior');
        }
        $steps['verifying_prior'] = self::transitionStep('verifying_prior');

        [$view, $priorVerify] = $this->recoverOperation(
            $view,
            $frozenTarget,
            'prior_verify',
            'verifying_prior'
        );
        $steps['prior_verify'] = self::operationStep('prior_verify', $priorVerify);

        if ((string) $view['status']['state'] === 'verifying_prior') {
            $view = $this->recoveryTransition(
                $view,
                $frozenTarget,
                'rolled_back',
                'rolled_back_verified',
                ['verifying_prior']
            );
        }
        if ((string) $view['status']['state'] !== 'rolled_back'
            || ($view['status']['terminal'] ?? null) !== true) {
            throw new \RuntimeException('wprism rollback: authorized recovery did not reach rolled_back terminal');
        }
        $steps['rolled_back_verified'] = self::transitionStep('rolled_back_verified');

        $releaseInput = hash('sha256', CanonicalJson::encode([
            'artifact_hash' => (string) $view['status']['artifact_hash'],
            'generation' => (int) $view['status']['generation'],
            'operation' => 'exclusion_release',
            'receipt_id' => (string) $view['status']['receipt_id'],
            'target_id' => (string) $view['status']['target_id'],
        ]) . "\n");
        if (($view['status']['exclusion_state'] ?? null) === 'held') {
            $this->authority->releaseExclusion();
            $view = $this->recoveryView($frozenTarget);
        }
        if (($view['status']['exclusion_state'] ?? null) !== 'released') {
            throw new \RuntimeException('wprism rollback: rolled-back generation has no released exclusion evidence');
        }
        $releaseResult = hash('sha256', CanonicalJson::encode([
            'exclusion_state' => 'released',
            'generation' => (int) $view['status']['generation'],
            'receipt_id' => (string) $view['status']['receipt_id'],
            'state' => (string) $view['status']['state'],
        ]) . "\n");
        $steps['exclusion_release'] = [
            'input_sha256' => $releaseInput,
            'result_sha256' => $releaseResult,
            'status' => 'completed',
            'step' => 'exclusion_release',
        ];

        $audit = RollbackAuthority::audit($this->transport);
        self::assertAuditIdentity($audit, $frozenTarget, 'rolled_back');
        $ordered = [];
        foreach (self::RECOVERY_STEP_ORDER as $name) {
            if (!isset($steps[$name])) {
                throw new \RuntimeException("wprism rollback: authorized recovery has no $name evidence");
            }
            $ordered[] = $steps[$name];
        }

        return [
            'audit' => $audit,
            'evidence' => $view['evidence'],
            'status' => $view['status'],
            'steps' => $ordered,
            'verification_sha256' => (string) $priorVerify['result_sha256'],
        ];
    }

    /**
     * Read-only admission check used immediately before authorization
     * consumption. It catches an open forward operation or a later state that
     * lacks the exact completed restore prefix while authority is still safe.
     *
     * @param array<string,mixed> $frozenTarget
     */
    public function assertAuthorizedRecoveryResumable(array $frozenTarget): void {
        $view = $this->recoveryView($frozenTarget, true);
        $this->assertExecutionReadiness($view);
        self::assertResumableView($view);
    }

    /** @return array<string,mixed> */
    public function status(): array {
        $status = RollbackAuthority::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true) {
            throw new \RuntimeException('wprism rollback: active verified receipt is unavailable or invalid');
        }
        return $status;
    }

    /**
     * @param array{evidence:array<string,mixed>,status:array<string,mixed>} $view
     */
    private static function assertResumableView(array $view): void {
        $status = $view['status'];
        $state = (string) ($status['state'] ?? '');
        if (!in_array($state, self::RECOVERY_START_STATES, true)) {
            throw new \RuntimeException('wprism rollback: frozen recovery state is not executable');
        }
        $open = (array) ($view['evidence']['open_operations'] ?? []);
        $history = (array) ($view['evidence']['completed_operation_history'] ?? []);
        $completed = (array) ($view['evidence']['completed_operations'] ?? []);
        if (in_array($state, ['prepared', 'promoting', 'verifying_new', 'rollback_pending'], true)) {
            if ($open !== []) {
                throw new \RuntimeException(
                    'wprism rollback: recovery cannot consume authority while another operation is prepared'
                );
            }
            foreach (array_merge(self::RECOVERY_OPERATIONS, ['prior_verify']) as $operation) {
                if (array_key_exists($operation, $history) || array_key_exists($operation, $completed)) {
                    throw new \RuntimeException(
                        'wprism rollback: recovery evidence exists before its signed rollback state'
                    );
                }
            }
            return;
        }

        $missingSeen = false;
        $nextMissing = null;
        foreach (self::RECOVERY_OPERATIONS as $operation) {
            $input = self::recoveryInput($operation, $status);
            $inputHash = self::inputHash($input);
            $done = $history[$operation] ?? null;
            if (is_array($done)) {
                if ($missingSeen) {
                    throw new \RuntimeException(
                        'wprism rollback: completed recovery operations do not form the exact ordered prefix'
                    );
                }
                self::assertOperationEvent($done, $operation, 'rolling_back', $inputHash, $status, false);
            } else {
                $missingSeen = true;
                $nextMissing ??= $operation;
            }
        }
        if ($state === 'verifying_prior' && $missingSeen) {
            throw new \RuntimeException(
                'wprism rollback: verifying_prior lacks the complete exact resource-restore history'
            );
        }
        if ($state === 'rolling_back'
            && (array_key_exists('prior_verify', $history) || array_key_exists('prior_verify', $completed))) {
            throw new \RuntimeException(
                'wprism rollback: prior verification evidence exists before the signed verifying_prior state'
            );
        }

        $allowedOpen = $state === 'rolling_back'
            ? ($nextMissing === null ? [] : [$nextMissing . '#1'])
            : ['prior_verify#1'];
        foreach ($open as $key => $event) {
            if (!is_string($key) || !in_array($key, $allowedOpen, true) || !is_array($event)) {
                throw new \RuntimeException('wprism rollback: active prepared operation is not the exact recovery resume point');
            }
            $operation = substr($key, 0, -2);
            $input = self::recoveryInput($operation, $status);
            self::assertPreparedEvent($event, $operation, $state, self::inputHash($input), $status);
        }
        if ($state === 'verifying_prior' && is_array($completed['prior_verify'] ?? null)) {
            self::assertOperationEvent(
                $completed['prior_verify'],
                'prior_verify',
                'verifying_prior',
                self::inputHash(self::recoveryInput('prior_verify', $status)),
                $status,
                true
            );
        }
    }

    /**
     * Prove that every executable needed by this full receipt is still
     * configured and answers its read-only preflight before actor authority is
     * consumed. The decorated status verifies the receipt-bound artifacts;
     * recoveryProbe() additionally invokes every configured provider/adapter,
     * including database_restore and prior_verify, which have no status
     * artifact of their own. Recovery v1 deliberately uses the controller key
     * that signed the frozen receipt; activeEvidence proves that key was
     * target-admitted, while the local authority constructor proves its secret
     * is still readable and usable.
     *
     * @param array{evidence:array<string,mixed>,status:array<string,mixed>} $view
     */
    private function assertExecutionReadiness(array $view): void {
        $status = $view['status'];
        $receipt = $view['evidence']['receipt'] ?? null;
        $missing = [];
        if (!$this->transport->rollbackConfigured()) $missing[] = 'rollback signing key';
        if (!$this->transport->recoveryConfigured()) $missing[] = 'recovery executor';
        if (!$this->transport->checkpointConfigured()) $missing[] = 'checkpoint provider';
        if (!$this->transport->codeReleaseConfigured()) $missing[] = 'code-release provider';
        if (!$this->transport->uploadProviderConfigured()) $missing[] = 'upload provider';
        if (!$this->transport->effectProviderConfigured()) $missing[] = 'effect provider';
        if (!$this->transport->verifiedRollbackConfigured()) $missing[] = 'verified_rollback policy';
        if ($missing !== []) {
            throw new \RuntimeException(
                'wprism rollback: authorized recovery is not executable: missing ' . implode(', ', $missing)
            );
        }
        if (($status['recovery_configured'] ?? null) !== true
            || ($status['recovery_ready'] ?? null) !== true) {
            throw new \RuntimeException('wprism rollback: target recovery providers are not ready');
        }
        if (($status['exclusion_state'] ?? null) !== 'held') {
            throw new \RuntimeException('wprism rollback: authorized recovery requires the exact held exclusion');
        }
        if (($status['automatic_code_rollback'] ?? null) !== true) {
            throw new \RuntimeException('wprism rollback: active receipt has no automatic code rollback');
        }
        if (!is_array($receipt)
            || !hash_equals($this->authority->keyId(), (string) ($receipt['signing_key_id'] ?? ''))) {
            throw new \RuntimeException(
                'wprism rollback: configured controller signing key does not own the active receipt'
            );
        }
        $this->authority->assertSigningKeyAdmitted();

        $boundEvidence = [
            'code_release' => ['metadata_sha256' => 'code_release_metadata_sha256'],
            'effects' => ['metadata_sha256' => 'lifecycle_receipts_sha256'],
            'uploads' => ['metadata_sha256' => 'uploads_inventory_sha256'],
        ];
        foreach ($boundEvidence as $name => $bindings) {
            $evidence = $status[$name] ?? null;
            if (!is_array($evidence) || ($evidence['deleted'] ?? false) === true) {
                throw new \RuntimeException("wprism rollback: active receipt has no bound $name evidence");
            }
            foreach ($bindings as $evidenceField => $statusField) {
                $evidenceHash = (string) ($evidence[$evidenceField] ?? '');
                $statusHash = (string) ($status[$statusField] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1
                    || preg_match('/^[a-f0-9]{64}$/D', $statusHash) !== 1
                    || !hash_equals($statusHash, $evidenceHash)) {
                    throw new \RuntimeException("wprism rollback: active receipt $name evidence is incomplete");
                }
            }
        }
        foreach (array_merge(self::RECOVERY_OPERATIONS, ['prior_verify']) as $operation) {
            $input = self::recoveryInput($operation, $status);
            foreach ($input as $field => $value) {
                if (str_ends_with($field, '_sha256')
                    && (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1)) {
                    throw new \RuntimeException(
                        "wprism rollback: active receipt cannot build the exact $operation input"
                    );
                }
            }
        }

        $probe = RollbackAuthority::recoveryProbe($this->transport);
        $adapters = $probe['adapters'] ?? null;
        if (($probe['available'] ?? null) !== true || ($probe['ok'] ?? null) !== true
            || !is_array($adapters)) {
            throw new \RuntimeException('wprism rollback: target recovery preflight failed');
        }
        foreach (['code_restore', 'database_restore', 'prior_verify', 'storage_restore'] as $adapter) {
            if (!is_array($adapters[$adapter] ?? null)) {
                throw new \RuntimeException("wprism rollback: target recovery preflight omitted $adapter");
            }
        }
        foreach (['checkpoint', 'code_release', 'effects', 'uploads'] as $provider) {
            if (!is_array($probe[$provider] ?? null)) {
                throw new \RuntimeException("wprism rollback: target recovery preflight omitted $provider");
            }
        }
    }

    /**
     * @param array{evidence:array<string,mixed>,status:array<string,mixed>} $view
     * @param array<string,mixed> $frozenTarget
     * @param list<string> $from
     * @return array{evidence:array<string,mixed>,status:array<string,mixed>}
     */
    private function recoveryTransition(
        array $view,
        array $frozenTarget,
        string $next,
        string $operation,
        array $from
    ): array {
        $status = $view['status'];
        if ((string) $status['state'] === $next) {
            return $view;
        }
        if (!in_array((string) $status['state'], $from, true)) {
            throw new \RuntimeException(
                "wprism rollback: cannot resume authorized recovery from {$status['state']} to $next"
            );
        }
        $this->authority->append(
            $next,
            'state_transition',
            $operation,
            1,
            (string) $status['claimant'],
            hash('sha256', $operation . ':input'),
            hash('sha256', $operation . ':result'),
            null,
            $status
        );

        return $this->recoveryView($frozenTarget);
    }

    /**
     * @param array{evidence:array<string,mixed>,status:array<string,mixed>} $view
     * @param array<string,mixed> $frozenTarget
     * @return array{0:array{evidence:array<string,mixed>,status:array<string,mixed>},1:array<string,mixed>}
     */
    private function recoverOperation(
        array $view,
        array $frozenTarget,
        string $operation,
        string $state
    ): array {
        $status = $view['status'];
        $input = self::recoveryInput($operation, $status);
        $inputHash = self::inputHash($input);
        $collection = $operation === 'prior_verify'
            ? (array) ($view['evidence']['completed_operations'] ?? [])
            : (array) ($view['evidence']['completed_operation_history'] ?? []);
        $done = $collection[$operation] ?? null;
        if (is_array($done)) {
            self::assertOperationEvent(
                $done,
                $operation,
                $state,
                $inputHash,
                $status,
                $operation === 'prior_verify'
            );
            return [$view, $done];
        }
        if ((string) $status['state'] !== $state) {
            throw new \RuntimeException("wprism rollback: $state passed without exact completed $operation evidence");
        }

        $open = (array) ($view['evidence']['open_operations'] ?? []);
        $prepared = $open[$operation . '#1'] ?? null;
        if ($prepared !== null && !is_array($prepared)) {
            throw new \RuntimeException("wprism rollback: prepared $operation evidence is malformed");
        }
        if (is_array($prepared)) {
            self::assertPreparedEvent($prepared, $operation, $state, $inputHash, $status);
            $preparedStatus = $status;
        } else {
            $preparedResult = $this->authority->prepareOperation(
                $state,
                $operation,
                1,
                $input,
                null,
                $status
            );
            $preparedStatus = (array) $preparedResult['status'];
        }
        $execution = $this->authority->executeOperation($operation, 1, $input, $preparedStatus);
        $this->authority->completeOperation(
            $state,
            $operation,
            1,
            $input,
            $execution,
            null,
            $preparedStatus
        );
        $fresh = $this->recoveryView($frozenTarget);
        $collection = $operation === 'prior_verify'
            ? (array) ($fresh['evidence']['completed_operations'] ?? [])
            : (array) ($fresh['evidence']['completed_operation_history'] ?? []);
        $done = $collection[$operation] ?? null;
        if (!is_array($done)) {
            throw new \RuntimeException("wprism rollback: completed $operation was not durably observable");
        }
        self::assertOperationEvent(
            $done,
            $operation,
            $state,
            $inputHash,
            $fresh['status'],
            $operation === 'prior_verify'
        );

        return [$fresh, $done];
    }

    /**
     * @param array<string,mixed> $frozenTarget
     * @return array{evidence:array<string,mixed>,status:array<string,mixed>}
     */
    private function recoveryView(array $frozenTarget, bool $strictStart = false): array {
        $evidence = RollbackAuthority::activeEvidence($this->transport);
        $raw = (array) $evidence['status'];
        $status = $this->status();
        self::assertSameStatusHead($raw, $status);
        self::assertFrozenIdentity($status, $frozenTarget, $strictStart);
        if ($strictStart) {
            $audit = RollbackAuthority::audit($this->transport);
            self::assertAuditIdentity($audit, $frozenTarget, (string) $status['state']);
            if (!hash_equals(
                (string) $frozenTarget['receipt_payload_sha256'],
                hash('sha256', RollbackControl::canonical((array) $evidence['receipt']))
            )) {
                throw new \RuntimeException('wprism rollback: active receipt payload changed before recovery');
            }
        }

        return ['evidence' => $evidence, 'status' => $status];
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private static function recoveryInput(string $operation, array $status): array {
        if ($operation === 'effects_inverse') {
            $effect = $status['effects'] ?? null;
            if (!is_array($effect)) throw new \RuntimeException('wprism rollback: receipt has no effect evidence');
            return [
                'artifact_hash' => (string) $status['artifact_hash'],
                'effects_inventory_sha256' => (string) ($effect['effects_inventory_sha256'] ?? ''),
                'format' => 'wprism-effect-operation/v1',
                'generation' => (int) $status['generation'],
                'lifecycle_receipts_sha256' => (string) ($effect['metadata_sha256'] ?? ''),
                'operation' => 'restore_prior',
                'owner' => (string) $status['owner'],
                'prior_evidence_sha256' => (string) ($effect['prior_evidence_sha256'] ?? ''),
                'receipt_id' => (string) $status['receipt_id'],
                'target_id' => (string) $status['target_id'],
            ];
        }
        if ($operation === 'storage_restore') {
            return self::uploadInput($status, 'restore_prior');
        }
        if ($operation === 'code_restore') {
            $code = $status['code_release'] ?? null;
            if (!is_array($code)) throw new \RuntimeException('wprism rollback: receipt has no code release evidence');
            return [
                'artifact_hash' => (string) $status['artifact_hash'],
                'code_release_metadata_sha256' => (string) ($code['metadata_sha256'] ?? ''),
                'expected_from_pointer_sha256' => (string) ($code['desired_pointer_sha256'] ?? ''),
                'format' => 'wprism-code-release-operation/v1',
                'generation' => (int) $status['generation'],
                'operation' => 'restore_prior',
                'owner' => (string) $status['owner'],
                'receipt_id' => (string) $status['receipt_id'],
                'target_id' => (string) $status['target_id'],
            ];
        }
        $checkpoint = (string) ($status['checkpoint_sha256'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $checkpoint) !== 1) {
            throw new \RuntimeException('wprism rollback: receipt has no checkpoint identity');
        }
        if ($operation === 'database_restore') {
            return ['checkpoint_sha256' => $checkpoint, 'operation' => 'database_restore'];
        }
        if ($operation === 'prior_verify') {
            return ['checkpoint_sha256' => $checkpoint, 'operation' => 'prior_verify'];
        }
        throw new \RuntimeException("wprism rollback: unknown authorized recovery operation $operation");
    }

    /** @param array<string,mixed> $input */
    private static function inputHash(array $input): string {
        return hash('sha256', CanonicalJson::encode($input) . "\n");
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $status */
    private static function assertPreparedEvent(
        array $event,
        string $operation,
        string $state,
        string $inputHash,
        array $status
    ): void {
        if (($event['operation_status'] ?? null) !== 'prepared'
            || (string) ($event['operation_id'] ?? '') !== $operation
            || (string) ($event['state'] ?? '') !== $state
            || (int) ($event['attempt'] ?? 0) !== 1
            || !hash_equals($inputHash, (string) ($event['input_sha256'] ?? ''))
            || (string) ($event['result_sha256'] ?? '') !== str_repeat('0', 64)
            || (int) ($event['claim_epoch'] ?? 0) !== (int) $status['claim_epoch']
            || (string) ($event['claimant'] ?? '') !== (string) $status['claimant']) {
            throw new \RuntimeException("wprism rollback: prepared $operation does not match the exact frozen input");
        }
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $status */
    private static function assertOperationEvent(
        array $event,
        string $operation,
        string $state,
        string $inputHash,
        array $status,
        bool $currentEpoch
    ): void {
        if (($event['operation_status'] ?? null) !== 'completed'
            || (string) ($event['operation_id'] ?? '') !== $operation
            || (string) ($event['state'] ?? '') !== $state
            || (int) ($event['attempt'] ?? 0) !== 1
            || !hash_equals($inputHash, (string) ($event['input_sha256'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($event['result_sha256'] ?? '')) !== 1
            || ($currentEpoch && ((int) ($event['claim_epoch'] ?? 0) !== (int) $status['claim_epoch']
                || (string) ($event['claimant'] ?? '') !== (string) $status['claimant']))) {
            throw new \RuntimeException("wprism rollback: completed $operation does not match the exact frozen input");
        }
    }

    /** @param array<string,mixed> $status @param array<string,mixed> $frozen */
    private static function assertFrozenIdentity(array $status, array $frozen, bool $strictStart): void {
        $expected = [
            'artifact_hash' => $frozen['artifact_hash'] ?? null,
            'claim_epoch' => $frozen['claim_epoch'] ?? null,
            'claimant' => $frozen['claimant'] ?? null,
            'generation' => $frozen['generation'] ?? null,
            'owner' => $frozen['owner'] ?? null,
            'receipt_id' => $frozen['receipt_id'] ?? null,
            'target_id' => $frozen['rollback_target_id'] ?? null,
        ];
        if ($strictStart) {
            $expected += [
                'claim_expires_at' => $frozen['claim_expires_at'] ?? null,
                'head_event_sha256' => $frozen['head_event_sha256'] ?? null,
                'sequence' => $frozen['sequence'] ?? null,
                'state' => $frozen['state'] ?? null,
            ];
        }
        foreach ($expected as $field => $value) {
            if (($status[$field] ?? null) !== $value) {
                throw new \RuntimeException("wprism rollback: frozen recovery target changed $field");
            }
        }
    }

    /** @param array<string,mixed> $raw @param array<string,mixed> $decorated */
    private static function assertSameStatusHead(array $raw, array $decorated): void {
        foreach ([
            'artifact_hash', 'claim_epoch', 'claimant', 'generation', 'head_event_sha256',
            'owner', 'receipt_id', 'sequence', 'state', 'target_id', 'terminal',
        ] as $field) {
            if (($raw[$field] ?? null) !== ($decorated[$field] ?? null)) {
                throw new \RuntimeException("wprism rollback: recovery evidence changed $field between verified reads");
            }
        }
    }

    /** @param array<string,mixed> $audit @param array<string,mixed> $frozen */
    private static function assertAuditIdentity(array $audit, array $frozen, string $state): void {
        $expected = [
            'generation' => $frozen['generation'] ?? null,
            'receipt_id' => $frozen['receipt_id'] ?? null,
            'state' => $state,
            'target_id' => $frozen['rollback_target_id'] ?? null,
        ];
        foreach ($expected as $field => $value) {
            if (($audit[$field] ?? null) !== $value) {
                throw new \RuntimeException("wprism rollback: recovery audit changed $field");
            }
        }
        if ($state !== 'rolled_back') {
            foreach ([
                'event_chain_sha256' => 'event_chain_sha256',
                'receipt_sha256' => 'receipt_envelope_sha256',
                'target_record_sha256' => 'target_record_sha256',
            ] as $auditField => $frozenField) {
                if (!hash_equals((string) ($audit[$auditField] ?? ''), (string) ($frozen[$frozenField] ?? ''))) {
                    throw new \RuntimeException("wprism rollback: frozen recovery audit changed $auditField");
                }
            }
        }
    }

    /** @return array{input_sha256:string,result_sha256:string,status:string,step:string} */
    private static function transitionStep(string $operation): array {
        return [
            'input_sha256' => hash('sha256', $operation . ':input'),
            'result_sha256' => hash('sha256', $operation . ':result'),
            'status' => 'completed',
            'step' => $operation,
        ];
    }

    /** @param array<string,mixed> $event @return array{input_sha256:string,result_sha256:string,status:string,step:string} */
    private static function operationStep(string $operation, array $event): array {
        return [
            'input_sha256' => (string) $event['input_sha256'],
            'result_sha256' => (string) $event['result_sha256'],
            'status' => 'completed',
            'step' => $operation,
        ];
    }

    /** @return array<string,mixed> */
    private function transition(string $state, string $operation): array {
        $status = $this->status();
        return $this->authority->append(
            $state,
            'state_transition',
            $operation,
            1,
            (string) $status['claimant'],
            hash('sha256', $operation . ':input'),
            hash('sha256', $operation . ':result')
        );
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private static function uploadInput(array $status, string $operation): array {
        $uploads = $status['uploads'] ?? null;
        if (!is_array($uploads)) throw new \RuntimeException('wprism rollback: receipt has no upload evidence');
        return [
            'artifact_hash' => (string) $status['artifact_hash'],
            'desired_inventory_sha256' => (string) ($uploads['desired_inventory_sha256'] ?? ''),
            'format' => 'wprism-upload-operation/v1',
            'generation' => (int) $status['generation'],
            'operation' => $operation,
            'owner' => (string) $status['owner'],
            'prior_inventory_sha256' => (string) ($uploads['prior_inventory_sha256'] ?? ''),
            'receipt_id' => (string) $status['receipt_id'],
            'target_id' => (string) $status['target_id'],
            'uploads_inventory_sha256' => (string) ($uploads['metadata_sha256'] ?? ''),
        ];
    }

    private static function timestamp(): string {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function timeValue(string $value): int {
        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC')
        );
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \RuntimeException('wprism rollback: timestamp must be canonical UTC seconds');
        }
        return $time->getTimestamp();
    }
}
