<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Recovery\RollbackControl;
use Duo\Recovery\CanonicalJson;

/**
 * Controller-side client for the adopted rollback authority runtime.
 *
 * Requests travel as mode-0600 uploaded canonical JSON files, never shell
 * arguments. The Ed25519 secret remains on the controller; the target sees
 * only signed receipts/events and the public key provisioned by adoption.
 */
final class RollbackAuthority {
    private SshTransport $transport;
    private string $keyId;
    private string $secretKey;

    public function __construct(SshTransport $transport) {
        if (!$transport->rollbackConfigured()) {
            throw new \RuntimeException(
                "env '{$transport->name()}': rollback authority needs rollback_key_id + rollback_signing_key"
            );
        }
        $keyId = $transport->rollbackKeyId();
        $keyPath = $transport->rollbackSigningKeyPath();
        if (!is_string($keyId) || !is_string($keyPath)) {
            throw new \RuntimeException('duo rollback: incomplete controller signing-key configuration');
        }
        $this->transport = $transport;
        $this->keyId = $keyId;
        $this->secretKey = self::readSecretKey($keyPath);
    }

    public function keyId(): string {
        return $this->keyId;
    }

    public function publicKeyBase64(): string {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }

    public function __destruct() {
        sodium_memzero($this->secretKey);
    }

    /**
     * Read and cryptographically verify target authority status. Missing
     * pre-DUO-3293 runtimes are reported as unavailable, not corruption.
     *
     * @return array<string,mixed>
     */
    public static function status(SshTransport $transport): array {
        return self::readStatus($transport, 'status');
    }

    /** Read signed authority even when exclusion adoption is the failing edge. */
    public static function authorityStatus(SshTransport $transport): array {
        return self::readStatus($transport, 'authority-status');
    }

    /** Read canonical hash-only evidence for the complete signed chain. */
    public static function audit(SshTransport $transport): array {
        return self::readStatus($transport, 'audit');
    }

    /** @return array<string,mixed> */
    private static function readStatus(SshTransport $transport, string $action): array {
        $runtime = self::runtimePath($transport);
        $root = self::controlRoot($transport);
        $script = 'if [ ! -f ' . escapeshellarg($runtime) . ' ]; then exit 44; fi; '
            . 'php ' . escapeshellarg($runtime) . ' ' . escapeshellarg($action)
            . ' --root=' . escapeshellarg($root);
        $result = $transport->captureRaw($script);
        if ($result['exit'] === 44) {
            // `available` is still the capability/fallback discriminator, but
            // absence is not successful verification. Keeping ok=false makes
            // a future caller that checks only `ok` fail closed.
            return [
                'active' => false,
                'available' => false,
                'error' => 'rollback authority runtime is unavailable',
                'ok' => false,
            ];
        }
        if ($result['exit'] !== 0) {
            return [
                'active' => null,
                'available' => true,
                'error' => trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']),
                'ok' => false,
            ];
        }
        try {
            $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['active' => null, 'available' => true, 'error' => 'malformed authority status JSON', 'ok' => false];
        }
        if (!is_array($decoded)
            || CanonicalJson::encode($decoded) . "\n" !== $result['stdout']
            || ($decoded['ok'] ?? null) !== true) {
            return ['active' => null, 'available' => true, 'error' => 'invalid authority status evidence', 'ok' => false];
        }
        return ['available' => true] + $decoded;
    }

    /**
     * Claim the target's exact next generation and publish the immutable
     * receipt plus its initial `prepared` event as one retryable operation.
     * Controller-owned identity fields must not be supplied by callers.
     *
     * @param array<string,mixed> $fields
     * @return array{receipt:array<string,mixed>,status:array<string,mixed>}
     */
    public function claim(array $fields, string $claimant, ?string $timestamp = null): array {
        foreach (['format', 'receipt_id', 'target_id', 'generation', 'signing_key_id'] as $owned) {
            if (array_key_exists($owned, $fields)) {
                throw new \RuntimeException("duo rollback: claim caller may not set controller-owned '$owned'");
            }
        }
        $checkpointConfigured = $this->transport->checkpointConfigured();
        $codeReleaseConfigured = $this->transport->codeReleaseConfigured();
        $uploadProviderConfigured = $this->transport->uploadProviderConfigured();
        $effectProviderConfigured = $this->transport->effectProviderConfigured();
        $receiptFormat = $codeReleaseConfigured
            ? RollbackControl::RECEIPT_FORMAT
            : 'duo-rollback-receipt/v1';
        if (!$codeReleaseConfigured) {
            // V1 remains the truthful schema for manual code recovery. A V2
            // receipt exists specifically to bind certified release metadata.
            unset($fields['code_release_metadata_sha256']);
        }
        $desiredCodeRevision = null;
        $desiredDescriptorHash = null;
        $desiredCodeInventory = null;
        $uploadInventory = null;
        $effectInventory = null;
        if ($uploadProviderConfigured) {
            if (array_key_exists('uploads_inventory_sha256', $fields)) {
                throw new \RuntimeException('duo rollback: uploads_inventory_sha256 is provider-owned when certified upload recovery is configured');
            }
            if (!is_array($fields['upload_inventory'] ?? null)) {
                throw new \RuntimeException('duo rollback: certified upload recovery needs the compiled upload_inventory');
            }
            $uploadInventory = $fields['upload_inventory'];
            unset($fields['upload_inventory']);
            if (!is_string($fields['retention_until'] ?? null) || $fields['retention_until'] === '') {
                throw new \RuntimeException('duo rollback: upload recovery claim needs retention_until');
            }
        } elseif (array_key_exists('upload_inventory', $fields)) {
            throw new \RuntimeException('duo rollback: upload_inventory requires a certified upload provider');
        }
        if ($effectProviderConfigured) {
            if (array_key_exists('lifecycle_receipts_sha256', $fields)) {
                throw new \RuntimeException('duo rollback: lifecycle_receipts_sha256 is provider-owned when certified effect recovery is configured');
            }
            if (!is_array($fields['effect_inventory'] ?? null) || !array_is_list($fields['effect_inventory'])) {
                throw new \RuntimeException('duo rollback: certified effect recovery needs the compiled effect_inventory');
            }
            $effectInventory = $fields['effect_inventory'];
            unset($fields['effect_inventory']);
            if (!is_string($fields['retention_until'] ?? null) || $fields['retention_until'] === '') {
                throw new \RuntimeException('duo rollback: effect recovery claim needs retention_until');
            }
        } elseif (array_key_exists('effect_inventory', $fields)) {
            throw new \RuntimeException('duo rollback: effect_inventory requires a certified effect provider');
        }
        if ($codeReleaseConfigured) {
            if (array_key_exists('prior_code_descriptor_sha256', $fields)
                || array_key_exists('code_release_metadata_sha256', $fields)) {
                throw new \RuntimeException(
                    'duo rollback: code release receipt hashes are provider-owned when certified code recovery is configured'
                );
            }
            if (!is_string($fields['desired_code_revision'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', (string) $fields['desired_code_revision']) !== 1) {
                throw new \RuntimeException('duo rollback: code release claim needs sha256 desired_code_revision');
            }
            $hasDescriptor = is_string($fields['desired_descriptor_sha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/', (string) $fields['desired_descriptor_sha256']) === 1;
            $hasInventory = is_array($fields['desired_code_inventory'] ?? null)
                && !array_is_list($fields['desired_code_inventory']);
            if ($hasDescriptor === $hasInventory) {
                throw new \RuntimeException(
                    'duo rollback: code release claim needs exactly one desired descriptor hash or compiled code inventory'
                );
            }
            $desiredCodeRevision = (string) $fields['desired_code_revision'];
            $desiredDescriptorHash = $hasDescriptor ? (string) $fields['desired_descriptor_sha256'] : null;
            $desiredCodeInventory = $hasInventory ? $fields['desired_code_inventory'] : null;
            unset(
                $fields['desired_code_revision'],
                $fields['desired_descriptor_sha256'],
                $fields['desired_code_inventory']
            );
            if (!is_string($fields['retention_until'] ?? null) || $fields['retention_until'] === '') {
                throw new \RuntimeException('duo rollback: code release claim needs retention_until');
            }
        }
        if ($checkpointConfigured) {
            foreach ([
                'checkpoint_sha256', 'created_at', 'ledger_session_sha256',
                'prior_verifier_inputs_sha256', 'runtime_fingerprints_sha256',
            ] as $owned) {
                if (array_key_exists($owned, $fields)) {
                    throw new \RuntimeException(
                        "duo rollback: $owned is checkpoint-provider-owned when checkpoint recovery is configured"
                    );
                }
            }
            foreach (['encryption_key_id', 'retention_until'] as $required) {
                if (!is_string($fields[$required] ?? null) || $fields[$required] === '') {
                    throw new \RuntimeException("duo rollback: checkpoint claim needs $required");
                }
            }
        }
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true) {
            throw new \RuntimeException('duo rollback: target authority runtime is unavailable or invalid');
        }
        if (($status['active'] ?? false) === true && empty($status['terminal'])) {
            throw new \RuntimeException(
                "duo rollback: target generation {$status['generation']} is still {$status['state']}"
            );
        }
        $now = $timestamp ?? self::timestamp();
        $generation = (int) ($status['generation'] ?? 0) + 1;
        $receiptId = bin2hex(random_bytes(24));
        if ($this->transport->recoveryConfigured()) {
            if (array_key_exists('exclusion_token_sha256', $fields)) {
                throw new \RuntimeException('duo rollback: exclusion_token_sha256 is provider-owned when recovery is configured');
            }
            if (($status['recovery_ready'] ?? false) !== true) {
                throw new \RuntimeException('duo rollback: recovery provider and adapters did not pass preflight');
            }
            $reservation = $status['exclusion_reservation'] ?? null;
            if (($status['exclusion_state'] ?? '') === 'held' && is_array($reservation)) {
                foreach ([
                    'artifact_hash' => (string) ($fields['artifact_hash'] ?? ''),
                    'claimant' => $claimant,
                    'generation' => $generation,
                    'owner' => (string) ($fields['owner'] ?? ''),
                ] as $key => $expected) {
                    if ((string) ($reservation[$key] ?? '') !== (string) $expected) {
                        throw new \RuntimeException("duo rollback: held exclusion reservation $key does not match this claim");
                    }
                }
                $receiptId = (string) ($reservation['receipt_id'] ?? '');
            }
            $exclusion = $this->sendExclusion([
                'action' => 'acquire',
                'artifact_hash' => (string) ($fields['artifact_hash'] ?? ''),
                'claim_epoch' => 1,
                'claimant' => $claimant,
                'format' => 'duo-exclusion-request/v1',
                'generation' => $generation,
                'owner' => (string) ($fields['owner'] ?? ''),
                'receipt_id' => $receiptId,
                'target_id' => (string) ($status['target_id'] ?? ''),
                'timestamp' => $now,
            ]);
            $fields['exclusion_token_sha256'] = (string) $exclusion['token_sha256'];
            if ($checkpointConfigured) {
                $checkpoint = $this->sendCheckpoint([
                    'action' => 'prepare',
                    'artifact_hash' => (string) ($fields['artifact_hash'] ?? ''),
                    'claim_epoch' => 1,
                    'claimant' => $claimant,
                    'encryption_key_id' => (string) $fields['encryption_key_id'],
                    'format' => 'duo-checkpoint-request/v1',
                    'generation' => $generation,
                    'owner' => (string) ($fields['owner'] ?? ''),
                    'receipt_id' => $receiptId,
                    'retention_until' => (string) $fields['retention_until'],
                    'target_id' => (string) ($status['target_id'] ?? ''),
                    'timestamp' => $now,
                ]);
                foreach ([
                    'checkpoint_sha256', 'created_at', 'ledger_session_sha256',
                    'prior_verifier_inputs_sha256', 'runtime_fingerprints_sha256',
                ] as $owned) {
                    $fields[$owned] = (string) ($checkpoint[$owned] ?? '');
                }
            }
            if ($codeReleaseConfigured) {
                $releaseRequest = [
                    'action' => 'prepare',
                    'artifact_hash' => (string) ($fields['artifact_hash'] ?? ''),
                    'claim_epoch' => 1,
                    'claimant' => $claimant,
                    'desired_code_revision' => $desiredCodeRevision,
                    'generation' => $generation,
                    'owner' => (string) ($fields['owner'] ?? ''),
                    'receipt_id' => $receiptId,
                    'retention_until' => (string) ($fields['retention_until'] ?? ''),
                    'target_id' => (string) ($status['target_id'] ?? ''),
                    'timestamp' => $now,
                ];
                if ($desiredCodeInventory !== null) {
                    $releaseRequest['desired_code_inventory'] = $desiredCodeInventory;
                    $releaseRequest['format'] = 'duo-code-release-request/v2';
                } else {
                    $releaseRequest['desired_descriptor_sha256'] = $desiredDescriptorHash;
                    $releaseRequest['format'] = 'duo-code-release-request/v1';
                }
                $release = $this->sendCodeRelease($releaseRequest);
                $fields['prior_code_descriptor_sha256'] = (string) ($release['prior_code_descriptor_sha256'] ?? '');
                $fields['code_release_metadata_sha256'] = (string) ($release['code_release_metadata_sha256'] ?? '');
            }
            if ($uploadProviderConfigured) {
                $uploads = $this->sendUploadBundle([
                    'action' => 'prepare',
                    'artifact_hash' => (string) ($fields['artifact_hash'] ?? ''),
                    'claim_epoch' => 1,
                    'claimant' => $claimant,
                    'format' => 'duo-upload-bundle-request/v1',
                    'generation' => $generation,
                    'inventory' => $uploadInventory,
                    'owner' => (string) ($fields['owner'] ?? ''),
                    'receipt_id' => $receiptId,
                    'retention_until' => (string) ($fields['retention_until'] ?? ''),
                    'target_id' => (string) ($status['target_id'] ?? ''),
                    'timestamp' => $now,
                ]);
                $fields['uploads_inventory_sha256'] = (string) ($uploads['uploads_inventory_sha256'] ?? '');
            }
            if ($effectProviderConfigured) {
                $effects = $this->sendEffectBundle([
                    'action' => 'prepare',
                    'artifact_hash' => (string) ($fields['artifact_hash'] ?? ''),
                    'claim_epoch' => 1,
                    'claimant' => $claimant,
                    'format' => 'duo-effect-bundle-request/v1',
                    'generation' => $generation,
                    'inventory' => $effectInventory,
                    'owner' => (string) ($fields['owner'] ?? ''),
                    'receipt_id' => $receiptId,
                    'retention_until' => (string) ($fields['retention_until'] ?? ''),
                    'target_id' => (string) ($status['target_id'] ?? ''),
                    'timestamp' => $now,
                ]);
                $fields['lifecycle_receipts_sha256'] = (string) ($effects['lifecycle_receipts_sha256'] ?? '');
            }
        }
        $receipt = $fields + [
            'format' => $receiptFormat,
            'generation' => $generation,
            'receipt_id' => $receiptId,
            'signing_key_id' => $this->keyId,
            'target_id' => (string) ($status['target_id'] ?? ''),
        ];
        $ttl = $receipt['claim_ttl_seconds'] ?? null;
        if (!is_int($ttl)) {
            throw new \RuntimeException('duo rollback: claim needs integer claim_ttl_seconds');
        }
        $signedReceipt = RollbackControl::sign($receipt, $this->keyId, $this->secretKey);
        $event = $this->eventPayload(
            $receipt,
            1,
            str_repeat('0', 64),
            'prepared',
            'state_transition',
            'promotion-claim',
            1,
            $claimant,
            1,
            hash('sha256', CanonicalJson::encode($receipt)),
            str_repeat('0', 64),
            $now,
            $ttl
        );
        $request = [
            'action' => 'claim',
            'event' => RollbackControl::sign($event, $this->keyId, $this->secretKey),
            'receipt' => $signedReceipt,
        ];
        return ['receipt' => $receipt, 'status' => $this->send($request)];
    }

    /**
     * Append a state transition or a prepared/completed resource operation.
     * The active target read supplies every immutable/session field; a stale
     * concurrent writer loses the target-side sequence/head compare-and-swap.
     *
     * @return array<string,mixed>
     */
    public function append(
        string $state,
        string $operationStatus,
        string $operationId,
        int $attempt,
        string $claimant,
        string $inputHash,
        string $resultHash,
        ?string $timestamp = null
    ): array {
        $status = $this->requiredActiveStatus();
        $now = $timestamp ?? self::timestamp();
        $receipt = self::receiptView($status);
        $event = $this->eventPayload(
            $receipt,
            (int) $status['sequence'] + 1,
            (string) $status['head_event_sha256'],
            $state,
            $operationStatus,
            $operationId,
            $attempt,
            $claimant,
            (int) $status['claim_epoch'],
            $inputHash,
            $resultHash,
            $now,
            (int) $status['claim_ttl_seconds']
        );
        return $this->send([
            'action' => 'append',
            'event' => RollbackControl::sign($event, $this->keyId, $this->secretKey),
            'receipt' => null,
        ]);
    }

    /** @return array<string,mixed> */
    public function takeover(string $newClaimant, ?string $timestamp = null): array {
        $status = $this->requiredActiveStatus();
        $now = $timestamp ?? self::timestamp();
        $receipt = self::receiptView($status);
        $event = $this->eventPayload(
            $receipt,
            (int) $status['sequence'] + 1,
            (string) $status['head_event_sha256'],
            (string) $status['state'],
            'takeover',
            'operator-takeover',
            1,
            $newClaimant,
            (int) $status['claim_epoch'] + 1,
            hash('sha256', (string) $status['head_event_sha256'] . ':' . $newClaimant),
            str_repeat('0', 64),
            $now,
            (int) $status['claim_ttl_seconds']
        );
        $next = $this->send([
            'action' => 'append',
            'event' => RollbackControl::sign($event, $this->keyId, $this->secretKey),
            'receipt' => null,
        ]);
        if ($this->transport->recoveryConfigured()) {
            $this->sendExclusion($this->exclusionPayload('adopt', $next, $now));
        }
        return $next;
    }

    /** Refresh provider liveness without weakening disconnect fail-closed behavior. */
    public function keepalive(?string $timestamp = null): array {
        $status = $this->requiredActiveStatus();
        if (!$this->transport->recoveryConfigured()) {
            throw new \RuntimeException('duo rollback: no recovery exclusion provider is configured');
        }
        return $this->sendExclusion($this->exclusionPayload('keepalive', $status, $timestamp ?? self::timestamp()));
    }

    /**
     * Authorize one exact target recovery operation. Keeping authorization,
     * execution, and completion as separate public steps is intentional: a
     * fresh controller can resume after losing either the local process or
     * the SSH command without inventing a second operation receipt.
     *
     * @param array<string,mixed> $input
     * @return array{input_sha256:string,status:array<string,mixed>}
     */
    public function prepareOperation(
        string $state,
        string $adapter,
        int $attempt,
        array $input,
        ?string $timestamp = null
    ): array {
        $status = $this->requiredActiveStatus();
        self::assertOperationIdentity($adapter, $attempt);
        if ((string) $status['state'] !== $state) {
            throw new \RuntimeException(
                "duo rollback: cannot prepare $adapter while target is {$status['state']} instead of $state"
            );
        }
        $inputHash = hash('sha256', CanonicalJson::encode($input) . "\n");
        $next = $this->append(
            $state,
            'prepared',
            $adapter,
            $attempt,
            (string) $status['claimant'],
            $inputHash,
            str_repeat('0', 64),
            $timestamp
        );
        return ['input_sha256' => $inputHash, 'status' => $next];
    }

    /**
     * Execute an already-authorized operation through the adopted target
     * runtime. The canonical input is uploaded as a mode-0600 handoff and is
     * removed on every observed exit; its digest must match the signed open
     * operation before any provider is invoked.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function executeOperation(string $adapter, int $attempt, array $input): array {
        $status = $this->requiredActiveStatus();
        self::assertOperationIdentity($adapter, $attempt);
        $local = tempnam(sys_get_temp_dir(), 'duo-rollback-input-');
        if ($local === false) {
            throw new \RuntimeException('duo rollback: could not allocate operation handoff');
        }
        $remote = '/tmp/duo-rollback-input-' . bin2hex(random_bytes(16)) . '.json';
        try {
            @chmod($local, 0600);
            $bytes = CanonicalJson::encode($input) . "\n";
            if (file_put_contents($local, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('duo rollback: could not write operation handoff');
            }
            $upload = $this->transport->uploadFile($local, $remote);
            if ($upload['exit'] !== 0) {
                throw new \RuntimeException('duo rollback: operation upload failed: ' . trim($upload['stderr']));
            }
            $runtime = self::runtimePath($this->transport);
            $root = self::controlRoot($this->transport);
            $script = 'set -eu; input=' . escapeshellarg($remote)
                . '; finish() { status=$?; rm -f "$input"; exit "$status"; }; trap finish EXIT; '
                . 'chmod 600 "$input"; php ' . escapeshellarg($runtime) . ' execute'
                . ' --root=' . escapeshellarg($root)
                . ' --adapter=' . escapeshellarg($adapter)
                . ' --operation-id=' . escapeshellarg($adapter)
                . ' --attempt=' . escapeshellarg((string) $attempt)
                . ' --claimant=' . escapeshellarg((string) $status['claimant'])
                . ' --claim-epoch=' . escapeshellarg((string) $status['claim_epoch'])
                . ' --input="$input"';
            $result = $this->transport->captureRaw($script);
            if ($result['exit'] !== 0) {
                $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
                throw new \RuntimeException(
                    'duo rollback: target operation refused' . ($detail !== '' ? ': ' . $detail : '')
                );
            }
            $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
            $inputHash = hash('sha256', $bytes);
            if (!is_array($decoded)
                || CanonicalJson::encode($decoded) . "\n" !== $result['stdout']
                || ($decoded['ok'] ?? null) !== true
                || ($decoded['adapter'] ?? null) !== $adapter
                || !hash_equals($inputHash, (string) ($decoded['input_sha256'] ?? ''))
                || preg_match('/^[a-f0-9]{64}$/', (string) ($decoded['result_sha256'] ?? '')) !== 1) {
                throw new \RuntimeException('duo rollback: target returned invalid operation evidence');
            }
            return $decoded;
        } finally {
            @unlink($local);
            $this->transport->captureRaw('rm -f ' . escapeshellarg($remote));
        }
    }

    /** @param array<string,mixed> $execution @return array<string,mixed> */
    public function completeOperation(
        string $state,
        string $adapter,
        int $attempt,
        array $input,
        array $execution,
        ?string $timestamp = null
    ): array {
        $status = $this->requiredActiveStatus();
        self::assertOperationIdentity($adapter, $attempt);
        $inputHash = hash('sha256', CanonicalJson::encode($input) . "\n");
        if (($execution['adapter'] ?? null) !== $adapter
            || !hash_equals($inputHash, (string) ($execution['input_sha256'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/', (string) ($execution['result_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('duo rollback: operation completion evidence does not match its input');
        }
        return $this->append(
            $state,
            'completed',
            $adapter,
            $attempt,
            (string) $status['claimant'],
            $inputHash,
            (string) $execution['result_sha256'],
            $timestamp
        );
    }

    /** @param array<string,mixed> $input @return array{execution:array<string,mixed>,status:array<string,mixed>} */
    public function runOperation(
        string $state,
        string $adapter,
        int $attempt,
        array $input,
        ?string $preparedAt = null,
        ?string $completedAt = null
    ): array {
        $this->prepareOperation($state, $adapter, $attempt, $input, $preparedAt);
        $execution = $this->executeOperation($adapter, $attempt, $input);
        $status = $this->completeOperation($state, $adapter, $attempt, $input, $execution, $completedAt);
        return ['execution' => $execution, 'status' => $status];
    }

    /** Finish provider adoption after a takeover response/SSH disconnect gap. */
    public function adoptExclusion(?string $expectedClaimant = null, ?string $timestamp = null): array {
        if (!$this->transport->recoveryConfigured()) {
            throw new \RuntimeException('duo rollback: no recovery exclusion provider is configured');
        }
        $status = self::authorityStatus($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true || !empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: exclusion adoption requires valid nonterminal authority');
        }
        if ($expectedClaimant !== null && !hash_equals($expectedClaimant, (string) $status['claimant'])) {
            throw new \RuntimeException('duo rollback: authority claimant does not match requested exclusion adopter');
        }
        return $this->sendExclusion($this->exclusionPayload('adopt', $status, $timestamp ?? self::timestamp()));
    }

    /** Release only after the target has verified a signed terminal receipt. */
    public function releaseExclusion(?string $timestamp = null): array {
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true || empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: exclusion release requires valid committed or rolled_back authority');
        }
        if (!$this->transport->recoveryConfigured()) {
            throw new \RuntimeException('duo rollback: no recovery exclusion provider is configured');
        }
        return $this->sendExclusion($this->exclusionPayload('release', $status, $timestamp ?? self::timestamp()));
    }

    /** Delete an elapsed checkpoint only after verified terminal authority. */
    public function deleteCheckpoint(?string $timestamp = null): array {
        if (!$this->transport->checkpointConfigured()) {
            throw new \RuntimeException('duo rollback: no checkpoint provider is configured');
        }
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true || empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: checkpoint deletion requires valid terminal authority');
        }
        return $this->sendCheckpoint([
            'action' => 'delete',
            'artifact_hash' => (string) $status['artifact_hash'],
            'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'],
            'encryption_key_id' => (string) $status['encryption_key_id'],
            'format' => 'duo-checkpoint-request/v1',
            'generation' => (int) $status['generation'],
            'owner' => (string) $status['owner'],
            'receipt_id' => (string) $status['receipt_id'],
            'retention_until' => (string) $status['retention_until'],
            'target_id' => (string) $status['target_id'],
            'timestamp' => $timestamp ?? self::timestamp(),
        ]);
    }

    /** Delete a retained prior code release only after its rollback window. */
    public function deleteCodeRelease(?string $timestamp = null): array {
        if (!$this->transport->codeReleaseConfigured()) {
            throw new \RuntimeException('duo rollback: no certified code release provider is configured');
        }
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true || empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: code release deletion requires valid terminal authority');
        }
        $release = $status['code_release'] ?? null;
        if (!is_array($release)) {
            throw new \RuntimeException('duo rollback: target omitted immutable code release evidence');
        }
        return $this->sendCodeRelease([
            'action' => 'delete',
            'artifact_hash' => (string) $status['artifact_hash'],
            'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'],
            'desired_code_revision' => (string) ($release['desired_code_revision'] ?? ''),
            'desired_descriptor_sha256' => (string) ($release['desired_descriptor_sha256'] ?? ''),
            'format' => 'duo-code-release-request/v1',
            'generation' => (int) $status['generation'],
            'owner' => (string) $status['owner'],
            'receipt_id' => (string) $status['receipt_id'],
            'retention_until' => (string) $status['retention_until'],
            'target_id' => (string) $status['target_id'],
            'timestamp' => $timestamp ?? self::timestamp(),
        ]);
    }

    /** Delete retained upload before-images after terminal authority and retention. */
    public function deleteUploadBundle(?string $timestamp = null): array {
        if (!$this->transport->uploadProviderConfigured()) {
            throw new \RuntimeException('duo rollback: no certified upload provider is configured');
        }
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true || empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: upload bundle deletion requires valid terminal authority');
        }
        return $this->sendUploadBundle([
            'action' => 'delete', 'artifact_hash' => (string) $status['artifact_hash'],
            'claim_epoch' => (int) $status['claim_epoch'], 'claimant' => (string) $status['claimant'],
            'format' => 'duo-upload-bundle-request/v1', 'generation' => (int) $status['generation'],
            'inventory' => null, 'owner' => (string) $status['owner'],
            'receipt_id' => (string) $status['receipt_id'], 'retention_until' => (string) $status['retention_until'],
            'target_id' => (string) $status['target_id'], 'timestamp' => $timestamp ?? self::timestamp(),
        ]);
    }

    /** @return array<string,mixed> */
    private function requiredActiveStatus(): array {
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true) {
            throw new \RuntimeException('duo rollback: no valid active target receipt exists');
        }
        if (!empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: terminal receipt cannot accept another event');
        }
        return $status;
    }

    /** @return array<string,mixed> */
    private static function receiptView(array $status): array {
        return [
            'artifact_hash' => (string) $status['artifact_hash'],
            'generation' => (int) $status['generation'],
            'owner' => (string) $status['owner'],
            'receipt_id' => (string) $status['receipt_id'],
            'target_id' => (string) $status['target_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function exclusionPayload(string $action, array $status, string $timestamp): array {
        return [
            'action' => $action,
            'artifact_hash' => (string) $status['artifact_hash'],
            'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'],
            'format' => 'duo-exclusion-request/v1',
            'generation' => (int) $status['generation'],
            'owner' => (string) $status['owner'],
            'receipt_id' => (string) $status['receipt_id'],
            'target_id' => (string) $status['target_id'],
            'timestamp' => $timestamp,
        ];
    }

    /** @return array<string,mixed> */
    private function eventPayload(
        array $receipt,
        int $sequence,
        string $previous,
        string $state,
        string $operationStatus,
        string $operationId,
        int $attempt,
        string $claimant,
        int $claimEpoch,
        string $inputHash,
        string $resultHash,
        string $timestamp,
        int $ttl
    ): array {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            throw new \RuntimeException('duo rollback: event timestamp must be canonical UTC seconds');
        }
        return [
            'artifact_hash' => (string) $receipt['artifact_hash'],
            'attempt' => $attempt,
            'claim_epoch' => $claimEpoch,
            'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $time->getTimestamp() + $ttl),
            'claimant' => $claimant,
            'format' => RollbackControl::EVENT_FORMAT,
            'generation' => (int) $receipt['generation'],
            'input_sha256' => $inputHash,
            'operation_id' => $operationId,
            'operation_status' => $operationStatus,
            'owner' => (string) $receipt['owner'],
            'previous_event_sha256' => $previous,
            'receipt_id' => (string) $receipt['receipt_id'],
            'result_sha256' => $resultHash,
            'sequence' => $sequence,
            'signing_key_id' => $this->keyId,
            'state' => $state,
            'target_id' => (string) $receipt['target_id'],
            'timestamp' => $timestamp,
        ];
    }

    /** @return array<string,mixed> */
    private function send(array $request): array {
        return $this->sendRemote($request, 'request');
    }

    /** @return array<string,mixed> */
    private function sendExclusion(array $payload): array {
        return $this->sendRemote(
            RollbackControl::sign($payload, $this->keyId, $this->secretKey),
            'exclusion-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendCheckpoint(array $payload): array {
        return $this->sendRemote(
            RollbackControl::sign($payload, $this->keyId, $this->secretKey),
            'checkpoint-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendCodeRelease(array $payload): array {
        return $this->sendRemote(
            RollbackControl::sign($payload, $this->keyId, $this->secretKey),
            'code-release-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendUploadBundle(array $payload): array {
        return $this->sendRemote(
            RollbackControl::sign($payload, $this->keyId, $this->secretKey),
            'upload-bundle-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendEffectBundle(array $payload): array {
        return $this->sendRemote(
            RollbackControl::sign($payload, $this->keyId, $this->secretKey),
            'effect-bundle-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendRemote(array $request, string $action): array {
        $local = tempnam(sys_get_temp_dir(), 'duo-rollback-request-');
        if ($local === false) {
            throw new \RuntimeException('duo rollback: could not allocate request handoff');
        }
        $token = bin2hex(random_bytes(16));
        $remote = '/tmp/duo-rollback-request-' . $token . '.json';
        try {
            @chmod($local, 0600);
            $bytes = CanonicalJson::encode($request) . "\n";
            if (file_put_contents($local, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('duo rollback: could not write request handoff');
            }
            $upload = $this->transport->uploadFile($local, $remote);
            if ($upload['exit'] !== 0) {
                throw new \RuntimeException('duo rollback: request upload failed: ' . trim($upload['stderr']));
            }
            $runtime = self::runtimePath($this->transport);
            $root = self::controlRoot($this->transport);
            $script = 'set -eu; request=' . escapeshellarg($remote)
                . '; finish() { status=$?; rm -f "$request"; exit "$status"; }; trap finish EXIT; '
                . 'php ' . escapeshellarg($runtime) . ' ' . escapeshellarg($action)
                . ' --root=' . escapeshellarg($root)
                . ' --request="$request"';
            $result = $this->transport->captureRaw($script);
            if ($result['exit'] !== 0) {
                $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
                throw new \RuntimeException('duo rollback: target request refused' . ($detail !== '' ? ': ' . $detail : ''));
            }
            $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)
                || CanonicalJson::encode($decoded) . "\n" !== $result['stdout']
                || ($decoded['ok'] ?? null) !== true) {
                throw new \RuntimeException('duo rollback: target returned invalid request evidence');
            }
            return $decoded;
        } finally {
            @unlink($local);
            $this->transport->captureRaw('rm -f ' . escapeshellarg($remote));
        }
    }

    private static function readSecretKey(string $path): string {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo rollback: signing key '$path' is missing or not a regular file");
        }
        $mode = fileperms($path);
        if (is_int($mode) && (($mode & 0077) !== 0)) {
            throw new \RuntimeException("duo rollback: signing key '$path' must not be group/world accessible");
        }
        $raw = file_get_contents($path);
        $secret = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || trim((string) $raw) !== base64_encode($secret)) {
            throw new \RuntimeException('duo rollback: signing key must be canonical base64 Ed25519 secret bytes');
        }
        return $secret;
    }

    private static function assertOperationIdentity(string $adapter, int $attempt): void {
        if ($attempt < 1 || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $adapter) !== 1) {
            throw new \RuntimeException('duo rollback: operation adapter/attempt is malformed');
        }
    }

    private static function controlRoot(SshTransport $transport): string {
        return rtrim($transport->repoPath(), '/') . '/.duo/control';
    }

    private static function runtimePath(SshTransport $transport): string {
        return self::controlRoot($transport) . '/recovery-runtime/rollback-control.php';
    }

    private static function timestamp(): string {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
