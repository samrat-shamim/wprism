<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/HostContracts/RecoveryTransport.php';
require_once __DIR__ . '/RecoveryProtocol/RecoveryProtocolCodec.php';


/**
 * Controller-side client for the adopted rollback authority runtime.
 *
 * Requests travel as mode-0600 uploaded canonical JSON files, never shell
 * arguments. The Ed25519 secret remains on the controller; the target sees
 * only signed receipts/events and the public key provisioned by adoption.
 */
final class RollbackAuthority {
    private RecoveryTransport $transport;
    private string $keyId;
    private string $secretKey;

    public function __construct(RecoveryTransport $transport) {
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
    public static function status(RecoveryTransport $transport): array {
        return self::readStatus($transport, 'status');
    }

    /** Read signed authority even when exclusion adoption is the failing edge. */
    public static function authorityStatus(RecoveryTransport $transport): array {
        return self::readStatus($transport, 'authority-status');
    }

    /**
     * Read the un-decorated checkpoint-only authority state.  Scoped
     * promotion must not run the ordinary status preflight: that preflight
     * intentionally probes optional code/upload/effect providers which this
     * receipt format never authorizes or requires.
     *
     * @return array<string,mixed>
     */
    public static function scopedStatus(RecoveryTransport $transport): array {
        $status = self::authorityStatus($transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true) {
            return $status;
        }
        if (($status['active'] ?? false) === true && empty($status['terminal'])
            && ($status['receipt_format'] ?? null) !== RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT) {
            throw new \RuntimeException('duo rollback: active authority is not a scoped promotion receipt');
        }
        return $status;
    }

    /**
     * Return the target-verified hash-only operation maps used to make a
     * controller restart resume, rather than re-authorize, an exact
     * checkpoint/terminal operation.
     *
     * @return array<string,mixed>
     */
    public static function scopedEvidence(RecoveryTransport $transport): array {
        $evidence = self::readControlAction($transport, 'active-evidence');
        $receipt = $evidence['receipt'] ?? null;
        $status = $evidence['status'] ?? null;
        if (!is_array($receipt) || !is_array($status)
            || ($receipt['format'] ?? null) !== RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT
            || ($status['receipt_format'] ?? null) !== RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT
            || !is_array($evidence['open_operations'] ?? null)
            || !is_array($evidence['completed_operations'] ?? null)
            || !is_array($evidence['completed_operation_history'] ?? null)) {
            throw new \RuntimeException('duo rollback: scoped authority evidence is malformed');
        }
        return $evidence;
    }

    /** Read canonical hash-only evidence for the complete signed chain. */
    public static function audit(RecoveryTransport $transport): array {
        return self::readStatus($transport, 'audit');
    }

    /** @return array<string,mixed> */
    private static function readStatus(RecoveryTransport $transport, string $action): array {
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
            || RecoveryProtocolCodec::canonical($decoded) . "\n" !== $result['stdout']
            || ($decoded['ok'] ?? null) !== true) {
            return ['active' => null, 'available' => true, 'error' => 'invalid authority status evidence', 'ok' => false];
        }
        return ['available' => true] + $decoded;
    }

    /** @return array<string,mixed> */
    private static function readControlAction(RecoveryTransport $transport, string $action): array {
        $runtime = self::runtimePath($transport);
        $root = self::controlRoot($transport);
        $script = 'if [ ! -f ' . escapeshellarg($runtime) . ' ]; then exit 44; fi; '
            . 'php ' . escapeshellarg($runtime) . ' ' . escapeshellarg($action)
            . ' --root=' . escapeshellarg($root);
        $result = $transport->captureRaw($script);
        if ($result['exit'] === 44) {
            throw new \RuntimeException('duo rollback: target authority runtime is unavailable');
        }
        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new \RuntimeException(
                'duo rollback: target authority evidence is unavailable' . ($detail !== '' ? ': ' . $detail : '')
            );
        }
        try {
            $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('duo rollback: malformed authority evidence JSON', 0, $e);
        }
        if (!is_array($decoded) || RecoveryProtocolCodec::canonical($decoded) . "\n" !== $result['stdout']) {
            throw new \RuntimeException('duo rollback: invalid authority evidence');
        }
        return $decoded;
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
            ? RecoveryProtocolCodec::RECEIPT_FORMAT
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
        $signedReceipt = RecoveryProtocolCodec::sign($receipt, $this->keyId, $this->secretKey);
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
            hash('sha256', RecoveryProtocolCodec::canonical($receipt)),
            str_repeat('0', 64),
            $now,
            $ttl
        );
        $request = [
            'action' => 'claim',
            'event' => RecoveryProtocolCodec::sign($event, $this->keyId, $this->secretKey),
            'receipt' => $signedReceipt,
        ];
        return ['receipt' => $receipt, 'status' => $this->send($request)];
    }

    /**
     * Claim a checkpoint-only scoped-promotion generation.
     *
     * This is deliberately not a flag on claim(): optional full-release
     * providers are configured at the target level, and a scoped state window
     * must never accidentally prepare their code/upload/effect evidence.  The
     * receipt id is deterministic from the immutable intent, while its
     * timestamp/retention are derived from the durable exclusion reservation.
     * A process that loses any response before claim publication can therefore
     * replay the same provider inputs and signed authority boundary.
     *
     * `$fields` is either the complete fresh-claim field set, or the three
     * immutable resume identifiers (allow_deletes, artifact_hash, owner,
     * scope_hash).  The
     * latter is intentional: after target Apply changes selected roots, a
     * restart must recover the already-signed generation rather than compare
     * its original witness inventory to a new live plan.
     *
     * @param array<string,mixed> $fields
     * @return array{receipt:array<string,mixed>,status:array<string,mixed>}
     */
    public function claimScoped(array $fields, string $claimant, ?string $timestamp = null): array {
        self::validateScopedClaimIntent($fields, $claimant);
        if (!$this->transport->recoveryConfigured() || !$this->transport->checkpointConfigured()) {
            throw new \RuntimeException(
                'duo rollback: scoped promotion requires configured exclusion and checkpoint recovery'
            );
        }

        $status = self::authorityStatus($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true) {
            throw new \RuntimeException('duo rollback: target authority runtime is unavailable or invalid');
        }
        $fullClaim = self::isFullScopedClaimFields($fields);
        if (($status['active'] ?? false) === true) {
            if (($status['receipt_format'] ?? null) !== RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT) {
                if (!empty($status['terminal'])) {
                    if (!$fullClaim) {
                        throw new \RuntimeException('duo rollback: a fresh scoped claim needs complete claim fields');
                    }
                } else {
                    throw new \RuntimeException(
                        "duo rollback: target generation {$status['generation']} is still {$status['state']}"
                    );
                }
            } else {
                $receipt = self::scopedReceiptFromStatus($status);
                if (empty($status['terminal'])) {
                    self::assertScopedClaimMatchesActive($receipt, $status, $fields, $claimant);
                    return ['receipt' => $receipt, 'status' => $status];
                }

                // A terminal receipt is resumable only while its independent
                // exclusion is still held.  A lost release response must not
                // manufacture a new generation, while a verified released
                // terminal receipt is safe to advance past.
                $terminal = self::status($this->transport);
                if (($terminal['available'] ?? false) !== true || ($terminal['ok'] ?? false) !== true
                    || ($terminal['receipt_format'] ?? null) !== RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT
                    || ($terminal['receipt_id'] ?? null) !== ($status['receipt_id'] ?? null)
                    || ($terminal['generation'] ?? null) !== ($status['generation'] ?? null)) {
                    throw new \RuntimeException('duo rollback: scoped terminal exclusion status is unavailable or inconsistent');
                }
                if (($terminal['exclusion_state'] ?? null) === 'held') {
                    self::assertScopedClaimMatchesActive($receipt, $terminal, $fields, $claimant);
                    return ['receipt' => $receipt, 'status' => $terminal];
                }
                if (($terminal['exclusion_state'] ?? null) !== 'released') {
                    throw new \RuntimeException('duo rollback: scoped terminal exclusion has an unknown state');
                }
                if (!$fullClaim) {
                    throw new \RuntimeException('duo rollback: a fresh scoped claim needs complete claim fields');
                }
            }
        }
        if (!$fullClaim) {
            throw new \RuntimeException('duo rollback: a fresh scoped claim needs complete claim fields');
        }
        self::validateScopedClaimFields($fields, $claimant);

        $generation = (int) ($status['generation'] ?? 0) + 1;
        $receiptId = self::scopedReceiptId(
            $fields,
            $claimant,
            (string) ($status['target_id'] ?? ''),
            $generation
        );
        $now = $timestamp ?? self::timestamp();
        self::assertCanonicalTimestamp($now, 'scoped claim timestamp');

        // Acquire first: the reservation timestamp is the durable origin for
        // retention and the first signed event.  Retrying after a lost acquire
        // response uses the same deterministic receipt id and gets that exact
        // original reservation back.
        $exclusion = $this->sendExclusion([
            'action' => 'acquire',
            'artifact_hash' => (string) $fields['artifact_hash'],
            'claim_epoch' => 1,
            'claimant' => $claimant,
            'format' => 'duo-exclusion-request/v1',
            'generation' => $generation,
            'owner' => (string) $fields['owner'],
            'receipt_id' => $receiptId,
            'target_id' => (string) ($status['target_id'] ?? ''),
            'timestamp' => $now,
        ]);

        // This decorated read is deliberately after acquire.  With a held
        // reservation it verifies only the exclusion provider; absent scoped
        // receipt fields make optional full-release providers manual rather
        // than requiring their status evidence.
        $reserved = self::status($this->transport);
        $reservation = $reserved['exclusion_reservation'] ?? null;
        if (($reserved['available'] ?? false) !== true || ($reserved['ok'] ?? false) !== true
            || ($reserved['exclusion_state'] ?? null) !== 'held' || !is_array($reservation)
            || ($reserved['recovery_ready'] ?? false) !== true) {
            throw new \RuntimeException('duo rollback: scoped promotion exclusion reservation is unavailable');
        }
        foreach ([
            'artifact_hash' => (string) $fields['artifact_hash'],
            'claimant' => $claimant,
            'generation' => $generation,
            'owner' => (string) $fields['owner'],
            'receipt_id' => $receiptId,
        ] as $key => $expected) {
            if ((string) ($reservation[$key] ?? '') !== (string) $expected) {
                throw new \RuntimeException("duo rollback: scoped exclusion reservation $key does not match this claim");
            }
        }
        $createdAt = (string) ($reservation['reserved_at'] ?? '');
        self::assertCanonicalTimestamp($createdAt, 'scoped exclusion reservation timestamp');
        $retentionUntil = self::formatTimestamp(
            self::timestampValue($createdAt) + (int) $fields['retention_seconds']
        );

        $checkpoint = $this->sendCheckpoint([
            'action' => 'prepare',
            'artifact_hash' => (string) $fields['artifact_hash'],
            'claim_epoch' => 1,
            'claimant' => $claimant,
            'encryption_key_id' => (string) $fields['encryption_key_id'],
            'format' => 'duo-checkpoint-request/v1',
            'generation' => $generation,
            'owner' => (string) $fields['owner'],
            'receipt_id' => $receiptId,
            'retention_until' => $retentionUntil,
            'target_id' => (string) ($status['target_id'] ?? ''),
            'timestamp' => $createdAt,
        ]);
        foreach ([
            'checkpoint_sha256', 'created_at', 'ledger_session_sha256',
            'prior_verifier_inputs_sha256', 'runtime_fingerprints_sha256',
        ] as $key) {
            if (!is_string($checkpoint[$key] ?? null) || $checkpoint[$key] === '') {
                throw new \RuntimeException("duo rollback: checkpoint provider omitted scoped $key");
            }
        }
        $checkpointCreated = (string) $checkpoint['created_at'];
        self::assertCanonicalTimestamp($checkpointCreated, 'scoped checkpoint creation timestamp');
        if (!hash_equals($createdAt, $checkpointCreated)) {
            throw new \RuntimeException('duo rollback: scoped checkpoint creation time diverged from exclusion reservation');
        }

        $receipt = [
            'adapter_versions_sha256' => (string) $fields['adapter_versions_sha256'],
            'allow_deletes' => (bool) $fields['allow_deletes'],
            'artifact_hash' => (string) $fields['artifact_hash'],
            'checkpoint_sha256' => (string) $checkpoint['checkpoint_sha256'],
            'claim_ttl_seconds' => (int) $fields['claim_ttl_seconds'],
            'created_at' => $checkpointCreated,
            'encryption_key_id' => (string) $fields['encryption_key_id'],
            'exclusion_token_sha256' => (string) $exclusion['token_sha256'],
            'format' => RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT,
            'generation' => $generation,
            'ledger_session_sha256' => (string) $checkpoint['ledger_session_sha256'],
            'owner' => (string) $fields['owner'],
            'prior_verifier_inputs_sha256' => (string) $checkpoint['prior_verifier_inputs_sha256'],
            'receipt_id' => $receiptId,
            'resources_inventory_sha256' => (string) $fields['resources_inventory_sha256'],
            'retention_until' => $retentionUntil,
            'runtime_fingerprints_sha256' => (string) $checkpoint['runtime_fingerprints_sha256'],
            'scope_hash' => (string) $fields['scope_hash'],
            'signing_key_id' => $this->keyId,
            'target_id' => (string) ($status['target_id'] ?? ''),
        ];
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
            hash('sha256', RecoveryProtocolCodec::canonical($receipt)),
            str_repeat('0', 64),
            $checkpointCreated,
            (int) $fields['claim_ttl_seconds']
        );
        $next = $this->send([
            'action' => 'claim',
            'event' => RecoveryProtocolCodec::sign($event, $this->keyId, $this->secretKey),
            'receipt' => RecoveryProtocolCodec::sign($receipt, $this->keyId, $this->secretKey),
        ]);
        return ['receipt' => $receipt, 'status' => $next];
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
            'event' => RecoveryProtocolCodec::sign($event, $this->keyId, $this->secretKey),
            'receipt' => null,
        ]);
    }

    /**
     * Append to the checkpoint-only generation without asking the ordinary
     * full-release status decorator to probe unrelated providers.
     *
     * @return array<string,mixed>
     */
    public function appendScoped(
        string $state,
        string $operationStatus,
        string $operationId,
        int $attempt,
        string $claimant,
        string $inputHash,
        string $resultHash,
        ?string $timestamp = null
    ): array {
        $status = $this->requiredScopedActiveStatus();
        // A target crash after publishing the event but before target.json
        // advances leaves one verified orphan next event.  Reconstruct its
        // timestamp from the committed head so a retry signs byte-identical
        // payload rather than producing a competing sequence file.
        $now = $timestamp ?? self::nextScopedEventTimestamp($status);
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
            'event' => RecoveryProtocolCodec::sign($event, $this->keyId, $this->secretKey),
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
            'event' => RecoveryProtocolCodec::sign($event, $this->keyId, $this->secretKey),
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

    /** @return array<string,mixed> */
    public function keepaliveScoped(?string $timestamp = null): array {
        $status = $this->requiredScopedActiveStatus();
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
        $inputHash = hash('sha256', RecoveryProtocolCodec::canonical($input) . "\n");
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
     * Prepare one checkpoint-only operation.  A caller must inspect
     * scopedEvidence() first when resuming: this method deliberately refuses
     * to turn an existing prepared operation into a new authority boundary.
     *
     * @param array<string,mixed> $input
     * @return array{input_sha256:string,status:array<string,mixed>}
     */
    public function prepareScopedOperation(
        string $state,
        string $adapter,
        int $attempt,
        array $input,
        ?string $timestamp = null
    ): array {
        $status = $this->requiredScopedActiveStatus();
        self::assertOperationIdentity($adapter, $attempt);
        if ((string) $status['state'] !== $state) {
            throw new \RuntimeException(
                "duo rollback: cannot prepare $adapter while target is {$status['state']} instead of $state"
            );
        }
        $inputHash = hash('sha256', RecoveryProtocolCodec::canonical($input) . "\n");
        $next = $this->appendScoped(
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
            $bytes = RecoveryProtocolCodec::canonical($input) . "\n";
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
                || RecoveryProtocolCodec::canonical($decoded) . "\n" !== $result['stdout']
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

    /**
     * Execute an exact prepared checkpoint operation under scoped authority.
     * This mirrors executeOperation() but sources only the raw signed control
     * status, so an optional full-release provider can never block a scoped
     * database restore/prior verifier.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function executeScopedOperation(string $adapter, int $attempt, array $input): array {
        $status = $this->requiredScopedActiveStatus();
        self::assertOperationIdentity($adapter, $attempt);
        $local = tempnam(sys_get_temp_dir(), 'duo-rollback-input-');
        if ($local === false) {
            throw new \RuntimeException('duo rollback: could not allocate operation handoff');
        }
        $remote = '/tmp/duo-rollback-input-' . bin2hex(random_bytes(16)) . '.json';
        try {
            @chmod($local, 0600);
            $bytes = RecoveryProtocolCodec::canonical($input) . "\n";
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
                || RecoveryProtocolCodec::canonical($decoded) . "\n" !== $result['stdout']
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
        $inputHash = hash('sha256', RecoveryProtocolCodec::canonical($input) . "\n");
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

    /** @param array<string,mixed> $execution @return array<string,mixed> */
    public function completeScopedOperation(
        string $state,
        string $adapter,
        int $attempt,
        array $input,
        array $execution,
        ?string $timestamp = null
    ): array {
        $status = $this->requiredScopedActiveStatus();
        self::assertOperationIdentity($adapter, $attempt);
        $inputHash = hash('sha256', RecoveryProtocolCodec::canonical($input) . "\n");
        if (($execution['adapter'] ?? null) !== $adapter
            || !hash_equals($inputHash, (string) ($execution['input_sha256'] ?? ''))
            || preg_match('/^[a-f0-9]{64}$/', (string) ($execution['result_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('duo rollback: operation completion evidence does not match its input');
        }
        return $this->appendScoped(
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

    /**
     * Release a terminal scoped authority exactly once.  The decorated status
     * is safe after a scoped terminal receipt: it sees omitted full-release
     * hashes as manual and verifies only the durable exclusion record.
     *
     * @return array<string,mixed>
     */
    public function releaseScopedExclusion(?string $timestamp = null): array {
        $status = self::status($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true || empty($status['terminal'])
            || ($status['receipt_format'] ?? null) !== RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT) {
            throw new \RuntimeException('duo rollback: scoped exclusion release requires valid terminal scoped authority');
        }
        if (($status['exclusion_state'] ?? null) === 'released') {
            return [
                'already_released' => true,
                'generation' => (int) $status['generation'],
                'ok' => true,
                'receipt_id' => (string) $status['receipt_id'],
            ];
        }
        if (($status['exclusion_state'] ?? null) !== 'held') {
            throw new \RuntimeException('duo rollback: scoped terminal authority has no held exclusion to release');
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
    private function requiredScopedActiveStatus(): array {
        $status = self::scopedStatus($this->transport);
        if (($status['available'] ?? false) !== true || ($status['ok'] ?? false) !== true
            || ($status['active'] ?? false) !== true) {
            throw new \RuntimeException('duo rollback: no valid active scoped target receipt exists');
        }
        if (!empty($status['terminal'])) {
            throw new \RuntimeException('duo rollback: terminal scoped receipt cannot accept another event');
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
            'format' => RecoveryProtocolCodec::EVENT_FORMAT,
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
            RecoveryProtocolCodec::sign($payload, $this->keyId, $this->secretKey),
            'exclusion-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendCheckpoint(array $payload): array {
        return $this->sendRemote(
            RecoveryProtocolCodec::sign($payload, $this->keyId, $this->secretKey),
            'checkpoint-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendCodeRelease(array $payload): array {
        return $this->sendRemote(
            RecoveryProtocolCodec::sign($payload, $this->keyId, $this->secretKey),
            'code-release-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendUploadBundle(array $payload): array {
        return $this->sendRemote(
            RecoveryProtocolCodec::sign($payload, $this->keyId, $this->secretKey),
            'upload-bundle-request'
        );
    }

    /** @return array<string,mixed> */
    private function sendEffectBundle(array $payload): array {
        return $this->sendRemote(
            RecoveryProtocolCodec::sign($payload, $this->keyId, $this->secretKey),
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
            $bytes = RecoveryProtocolCodec::canonical($request) . "\n";
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
                || RecoveryProtocolCodec::canonical($decoded) . "\n" !== $result['stdout']
                || ($decoded['ok'] ?? null) !== true) {
                throw new \RuntimeException('duo rollback: target returned invalid request evidence');
            }
            return $decoded;
        } finally {
            @unlink($local);
            $this->transport->captureRaw('rm -f ' . escapeshellarg($remote));
        }
    }

    /** @param array<string,mixed> $fields */
    private static function validateScopedClaimIntent(array $fields, string $claimant): void {
        $keys = array_keys($fields);
        sort($keys, SORT_STRING);
        $resume = ['allow_deletes', 'artifact_hash', 'owner', 'scope_hash'];
        $full = [
            'adapter_versions_sha256',
            'allow_deletes',
            'artifact_hash',
            'claim_ttl_seconds',
            'encryption_key_id',
            'owner',
            'resources_inventory_sha256',
            'retention_seconds',
            'scope_hash',
        ];
        sort($resume, SORT_STRING);
        sort($full, SORT_STRING);
        if ($keys !== $resume && $keys !== $full) {
            throw new \RuntimeException('duo rollback: scoped claim has missing or unknown fields');
        }
        foreach (['artifact_hash', 'scope_hash'] as $key) {
            self::assertSha256((string) $fields[$key], "scoped claim $key");
        }
        if (!is_bool($fields['allow_deletes'] ?? null)) {
            throw new \RuntimeException('duo rollback: scoped claim allow_deletes must be boolean');
        }
        self::assertActor((string) $fields['owner'], 'scoped promotion owner');
        self::assertActor($claimant, 'scoped claimant');
    }

    /** @param array<string,mixed> $fields */
    private static function isFullScopedClaimFields(array $fields): bool {
        $actual = array_keys($fields);
        $expected = [
            'adapter_versions_sha256',
            'allow_deletes',
            'artifact_hash',
            'claim_ttl_seconds',
            'encryption_key_id',
            'owner',
            'resources_inventory_sha256',
            'retention_seconds',
            'scope_hash',
        ];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    /** @param array<string,mixed> $fields */
    private static function validateScopedClaimFields(array $fields, string $claimant): void {
        if (!self::isFullScopedClaimFields($fields)) {
            throw new \RuntimeException('duo rollback: scoped claim has missing or unknown fields');
        }
        foreach ([
            'adapter_versions_sha256', 'artifact_hash', 'resources_inventory_sha256', 'scope_hash',
        ] as $key) {
            self::assertSha256((string) $fields[$key], "scoped claim $key");
        }
        if (!is_bool($fields['allow_deletes'] ?? null)) {
            throw new \RuntimeException('duo rollback: scoped claim allow_deletes must be boolean');
        }
        if (!is_int($fields['claim_ttl_seconds'])
            || $fields['claim_ttl_seconds'] < 30 || $fields['claim_ttl_seconds'] > 3600) {
            throw new \RuntimeException('duo rollback: scoped claim TTL must be 30..3600 seconds');
        }
        if (!is_int($fields['retention_seconds'])
            || $fields['retention_seconds'] < 60 || $fields['retention_seconds'] > 31536000) {
            throw new \RuntimeException('duo rollback: scoped claim retention must be 60..31536000 seconds');
        }
        self::assertActor((string) $fields['encryption_key_id'], 'scoped encryption key id');
        self::assertActor((string) $fields['owner'], 'scoped promotion owner');
        self::assertActor($claimant, 'scoped claimant');
    }

    /** @param array<string,mixed> $fields */
    private static function scopedReceiptId(array $fields, string $claimant, string $targetId, int $generation): string {
        if (preg_match('/^[a-f0-9]{32}$/', $targetId) !== 1 || $generation < 1) {
            throw new \RuntimeException('duo rollback: scoped claim target generation is malformed');
        }
        return hash('sha256', RecoveryProtocolCodec::canonical([
            'allow_deletes' => (bool) $fields['allow_deletes'],
            'artifact_hash' => (string) $fields['artifact_hash'],
            'claimant' => $claimant,
            'format' => RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT,
            'generation' => $generation,
            'owner' => (string) $fields['owner'],
            'scope_hash' => (string) $fields['scope_hash'],
            'target_id' => $targetId,
        ]));
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private static function scopedReceiptFromStatus(array $status): array {
        $required = [
            'adapter_versions_sha256', 'allow_deletes', 'artifact_hash', 'checkpoint_sha256', 'claim_ttl_seconds',
            'created_at', 'encryption_key_id', 'exclusion_token_sha256', 'generation',
            'ledger_session_sha256', 'owner', 'prior_verifier_inputs_sha256', 'receipt_id',
            'resources_inventory_sha256', 'retention_until', 'runtime_fingerprints_sha256',
            'scope_hash', 'signing_key_id', 'target_id',
        ];
        foreach ($required as $key) {
            if (!array_key_exists($key, $status)) {
                throw new \RuntimeException("duo rollback: scoped authority status omitted $key");
            }
        }
        if (!is_bool($status['allow_deletes'])) {
            throw new \RuntimeException('duo rollback: scoped authority status allow_deletes is malformed');
        }
        $receipt = [
            'adapter_versions_sha256' => (string) $status['adapter_versions_sha256'],
            'allow_deletes' => (bool) $status['allow_deletes'],
            'artifact_hash' => (string) $status['artifact_hash'],
            'checkpoint_sha256' => (string) $status['checkpoint_sha256'],
            'claim_ttl_seconds' => (int) $status['claim_ttl_seconds'],
            'created_at' => (string) $status['created_at'],
            'encryption_key_id' => (string) $status['encryption_key_id'],
            'exclusion_token_sha256' => (string) $status['exclusion_token_sha256'],
            'format' => RecoveryProtocolCodec::SCOPED_PROMOTION_RECEIPT_FORMAT,
            'generation' => (int) $status['generation'],
            'ledger_session_sha256' => (string) $status['ledger_session_sha256'],
            'owner' => (string) $status['owner'],
            'prior_verifier_inputs_sha256' => (string) $status['prior_verifier_inputs_sha256'],
            'receipt_id' => (string) $status['receipt_id'],
            'resources_inventory_sha256' => (string) $status['resources_inventory_sha256'],
            'retention_until' => (string) $status['retention_until'],
            'runtime_fingerprints_sha256' => (string) $status['runtime_fingerprints_sha256'],
            'scope_hash' => (string) $status['scope_hash'],
            'signing_key_id' => (string) $status['signing_key_id'],
            'target_id' => (string) $status['target_id'],
        ];
        $reported = $status['receipt_payload_sha256'] ?? null;
        $actual = hash('sha256', RecoveryProtocolCodec::canonical($receipt));
        if (!is_string($reported) || !hash_equals($actual, $reported)) {
            throw new \RuntimeException('duo rollback: scoped authority status receipt hash does not verify');
        }
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $status @param array<string,mixed> $fields */
    private static function assertScopedClaimMatchesActive(
        array $receipt,
        array $status,
        array $fields,
        string $claimant
    ): void {
        foreach (['artifact_hash', 'owner', 'scope_hash'] as $key) {
            if ((string) $receipt[$key] !== (string) $fields[$key]) {
                throw new \RuntimeException("duo rollback: active scoped receipt $key does not match this claim");
            }
        }
        if (($receipt['allow_deletes'] ?? null) !== ($fields['allow_deletes'] ?? null)) {
            throw new \RuntimeException('duo rollback: active scoped receipt allow_deletes does not match this claim');
        }
        if (!hash_equals($claimant, (string) ($status['claimant'] ?? ''))) {
            throw new \RuntimeException('duo rollback: active scoped authority claimant does not match this claim');
        }
        // The receipt was already signed and hash-verified by target status.
        // Do not recompute its id/retention from a current plan/policy: those
        // witnesses legitimately change after target Apply and are relevant
        // only when minting a fresh generation.
    }

    private static function assertSha256(string $value, string $label): void {
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

    private static function assertCanonicalTimestamp(string $value, string $label): void {
        self::timestampValue($value, $label);
    }

    private static function timestampValue(string $value, string $label = 'timestamp'): int {
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

    private static function formatTimestamp(int $timestamp): string {
        return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
    }

    /** @param array<string,mixed> $status */
    private static function nextScopedEventTimestamp(array $status): string {
        return self::formatTimestamp(
            self::timestampValue((string) ($status['last_event_at'] ?? ''), 'scoped authority last event') + 1
        );
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

    private static function controlRoot(RecoveryTransport $transport): string {
        return rtrim($transport->repoPath(), '/') . '/.duo/control';
    }

    private static function runtimePath(RecoveryTransport $transport): string {
        return self::controlRoot($transport) . '/recovery-runtime/rollback-control.php';
    }

    private static function timestamp(): string {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
