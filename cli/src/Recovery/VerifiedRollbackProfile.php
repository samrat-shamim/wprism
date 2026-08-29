<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

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
        ?array $codeReleaseIdentity = null
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
    public function claim(array $plan, string $owner, string $claimant, ?string $timestamp = null): array {
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
            self::claimFields($plan, $policy, $owner, $timestamp),
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

    /** @return array<string,mixed> */
    public function status(): array {
        $status = RollbackAuthority::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true) {
            throw new \RuntimeException('wprism rollback: active verified receipt is unavailable or invalid');
        }
        return $status;
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
