<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Encrypted database before-image authority.
 *
 * Database/KMS mechanics remain target-owned. This runtime constrains their
 * protocol, verifies the ciphertext and frozen verifier inputs itself, binds
 * immutable metadata to the signed receipt, and authorizes restore/deletion
 * only through the external receipt generation and operation journal.
 */
final class CheckpointBundle {
    private const REQUEST_FORMAT = 'duo-checkpoint-request/v1';
    private const PROVIDER_REQUEST_FORMAT = 'duo-checkpoint-provider-request/v1';
    private const PROVIDER_RESPONSE_FORMAT = 'duo-checkpoint-provider-response/v1';
    private const METADATA_FORMAT = 'duo-checkpoint-metadata/v1';
    private const TOMBSTONE_FORMAT = 'duo-checkpoint-tombstone/v1';

    public static function configured(string $root): bool {
        return array_key_exists('checkpoint_provider', RecoveryExecutor::configuration($root));
    }

    /** @return array<string,mixed> */
    public static function probe(string $root): array {
        $config = RecoveryExecutor::configuration($root);
        if (!array_key_exists('checkpoint_provider', $config)) {
            throw new \RuntimeException('duo checkpoint: no checkpoint provider is configured');
        }
        $status = RollbackControl::status($root);
        $response = self::call($config, [
            'action' => 'probe',
            'artifact_directory' => null,
            'artifact_hash' => null,
            'checkpoint_metadata_sha256' => null,
            'claim_epoch' => null,
            'claimant' => null,
            'database_identity_sha256' => null,
            'encryption_key_id' => null,
            'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => (int) $status['generation'],
            'input_path' => null,
            'input_sha256' => null,
            'owner' => null,
            'receipt_id' => null,
            'target_id' => (string) $status['target_id'],
        ]);
        self::assertExactKeys($response, [
            'available', 'format', 'plaintext_durable', 'provider_id',
            'provider_version', 'state', 'streaming_authenticated_encryption',
            'temporary_plaintext_cleaned',
        ], 'probe response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'ready'
            || ($response['plaintext_durable'] ?? null) !== false
            || ($response['streaming_authenticated_encryption'] ?? null) !== true
            || ($response['temporary_plaintext_cleaned'] ?? null) !== true) {
            throw new \RuntimeException('duo checkpoint: provider did not attest safe streaming checkpoint behavior');
        }
        self::assertActor((string) ($response['provider_id'] ?? ''), 'provider id');
        self::assertActor((string) ($response['provider_version'] ?? ''), 'provider version');
        return ['ok' => true] + $response;
    }

    /** @return array<string,mixed> */
    public static function handleRequest(string $root, string $requestPath): array {
        self::assertAbsoluteRegularFile($requestPath, 'signed request');
        $signed = self::readCanonical($requestPath, 'signed request');
        $payload = RollbackControl::verifyEnvelope($root, $signed, 'checkpoint request');
        self::validateRequest($payload);
        return self::withLock($root, function () use ($root, $payload): array {
            $status = RollbackControl::status($root);
            if ((string) $payload['action'] === 'prepare') {
                if (!empty($status['active']) && empty($status['terminal'])) {
                    throw new \RuntimeException('duo checkpoint: prepare refused during a nonterminal generation');
                }
                if ((int) $payload['generation'] !== (int) $status['generation'] + 1
                    || (int) $payload['claim_epoch'] !== 1
                    || !hash_equals((string) $payload['target_id'], (string) $status['target_id'])) {
                    throw new \RuntimeException('duo checkpoint: prepare is not for the exact next target generation');
                }
                $recovery = RecoveryExecutor::decorateStatus($root, $status);
                $reservation = $recovery['exclusion_reservation'] ?? null;
                if (($recovery['exclusion_state'] ?? '') !== 'held' || !is_array($reservation)) {
                    throw new \RuntimeException('duo checkpoint: prepare requires a verified held exclusion reservation');
                }
                foreach (['artifact_hash', 'claim_epoch', 'claimant', 'generation', 'owner', 'receipt_id'] as $key) {
                    if ((string) ($payload[$key] ?? '') !== (string) ($reservation[$key] ?? '')) {
                        throw new \RuntimeException("duo checkpoint: exclusion reservation $key does not match prepare");
                    }
                }
                return self::prepare($root, $payload);
            }
            if (empty($status['active']) || empty($status['terminal'])) {
                throw new \RuntimeException('duo checkpoint: deletion requires signed terminal authority');
            }
            self::assertIdentity($payload, $status, true);
            return self::delete($root, $payload, $status);
        });
    }

    /** Called inside RollbackControl's target lock before receipt publication. */
    public static function assertClaimCheckpoint(string $root, array $receipt, array $event): void {
        $metadata = self::metadata($root, (string) $receipt['receipt_id']);
        self::assertIdentity($metadata, $receipt, false);
        if ((string) $metadata['claimant'] !== (string) $event['claimant']
            || (int) $metadata['claim_epoch'] !== (int) $event['claim_epoch']
            || !hash_equals(self::metadataHash($metadata), (string) $receipt['checkpoint_sha256'])
            || !hash_equals((string) $metadata['prior_verifier_inputs_sha256'], (string) $receipt['prior_verifier_inputs_sha256'])
            || !hash_equals((string) $metadata['runtime_fingerprints_sha256'], (string) $receipt['runtime_fingerprints_sha256'])
            || !hash_equals((string) $metadata['ledger_session_sha256'], (string) $receipt['ledger_session_sha256'])
            || !hash_equals((string) $metadata['encryption_key_id'], (string) $receipt['encryption_key_id'])
            || (string) $metadata['created_at'] !== (string) $receipt['created_at']
            || (string) $metadata['retention_until'] !== (string) $receipt['retention_until']) {
            throw new \RuntimeException('duo checkpoint: prepared checkpoint is not bound to the exact claim receipt');
        }
        self::verifyArtifacts($root, $metadata);
    }

    /**
     * Execute a receipt-authorized database restore or prior verifier.
     *
     * @return array{adapter_version:string,result_sha256:string}
     */
    public static function execute(
        string $root,
        string $adapter,
        string $inputPath,
        string $inputHash,
        array $status
    ): array {
        if (!in_array($adapter, ['database_restore', 'prior_verify'], true)) {
            throw new \RuntimeException('duo checkpoint: unsupported checkpoint operation');
        }
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::assertIdentity($metadata, $status, false);
        if (!hash_equals(self::metadataHash($metadata), (string) $status['checkpoint_sha256'])) {
            throw new \RuntimeException('duo checkpoint: receipt checkpoint hash does not match immutable metadata');
        }
        self::verifyArtifacts($root, $metadata);
        $config = RecoveryExecutor::configuration($root);
        $action = $adapter === 'database_restore' ? 'restore' : 'verify-prior';
        $response = self::call($config, self::providerOperationRequest(
            $root,
            $metadata,
            $status,
            $action,
            $inputPath,
            $inputHash
        ));
        if ($action === 'restore') {
            return self::validateRestore($root, $metadata, $response, $inputHash);
        }
        return self::validatePriorVerification($root, $metadata, $response, $inputHash);
    }

    /** @return array<string,mixed> */
    private static function prepare(string $root, array $payload): array {
        $dir = self::receiptDirectory($root, (string) $payload['receipt_id']);
        self::ensureDirectory($dir, 0700);
        self::ensureDirectory($dir . '/artifacts', 0700);
        self::ensureDirectory($dir . '/reports', 0700);
        $metadataPath = $dir . '/checkpoint-metadata.json';
        if (is_file($metadataPath)) {
            $metadata = self::readCanonical($metadataPath, 'checkpoint metadata');
            self::validateMetadata($metadata);
            self::assertIdentity($payload, $metadata, true);
            if ((string) $payload['encryption_key_id'] !== (string) $metadata['encryption_key_id']
                || (string) $payload['retention_until'] !== (string) $metadata['retention_until']) {
                throw new \RuntimeException('duo checkpoint: prepare retry changed key or retention inputs');
            }
            self::verifyArtifacts($root, $metadata);
            return self::publicMetadata($metadata);
        }
        $config = RecoveryExecutor::configuration($root);
        $cipherPath = $dir . '/artifacts/checkpoint.enc';
        $verifierPath = $dir . '/artifacts/prior-verifier-inputs.json';
        foreach ([$cipherPath, $verifierPath] as $path) {
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('duo checkpoint: prepare output path is unsafe');
            }
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('duo checkpoint: could not clear interrupted prepare output');
            }
        }
        self::syncDirectory($dir . '/artifacts');
        $response = self::call($config, [
            'action' => 'prepare',
            'artifact_directory' => $dir . '/artifacts',
            'artifact_hash' => (string) $payload['artifact_hash'],
            'checkpoint_metadata_sha256' => null,
            'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'],
            'database_identity_sha256' => null,
            'encryption_key_id' => (string) $payload['encryption_key_id'],
            'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => (int) $payload['generation'],
            'input_path' => null,
            'input_sha256' => null,
            'owner' => (string) $payload['owner'],
            'receipt_id' => (string) $payload['receipt_id'],
            'target_id' => (string) $payload['target_id'],
        ]);
        self::validatePrepareResponse($response, $cipherPath, $verifierPath, (string) $payload['encryption_key_id']);
        self::assertAbsoluteRegularFile($cipherPath, 'encrypted checkpoint');
        self::assertAbsoluteRegularFile($verifierPath, 'prior verifier inputs');
        $verifierInputs = self::readCanonical($verifierPath, 'prior verifier inputs');
        self::validateVerifierInputs($verifierInputs);
        $cipherHash = hash_file('sha256', $cipherPath);
        $verifierHash = hash_file('sha256', $verifierPath);
        $size = filesize($cipherPath);
        if (!is_string($cipherHash) || !is_string($verifierHash) || !is_int($size) || $size < 1
            || !hash_equals($cipherHash, (string) $response['ciphertext_sha256'])
            || !hash_equals($verifierHash, (string) $response['prior_verifier_inputs_sha256'])
            || !hash_equals((string) $verifierInputs['runtime_fingerprints_sha256'], (string) $response['runtime_fingerprints_sha256'])
            || !hash_equals((string) $verifierInputs['ledger_session_sha256'], (string) $response['ledger_session_sha256'])
            || $size !== (int) $response['ciphertext_size']) {
            throw new \RuntimeException('duo checkpoint: provider evidence does not match prepared artifacts');
        }
        @chmod($cipherPath, 0600);
        @chmod($verifierPath, 0600);
        $metadata = [
            'algorithm' => (string) $response['algorithm'],
            'artifact_hash' => (string) $payload['artifact_hash'],
            'ciphertext_path' => 'artifacts/checkpoint.enc',
            'ciphertext_sha256' => $cipherHash,
            'ciphertext_size' => $size,
            'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'],
            'created_at' => (string) $payload['timestamp'],
            'database_identity_sha256' => (string) $response['database_identity_sha256'],
            'disposable_import_sha256' => (string) $response['disposable_import_sha256'],
            'encryption_key_id' => (string) $payload['encryption_key_id'],
            'export_evidence_sha256' => (string) $response['export_evidence_sha256'],
            'format' => self::METADATA_FORMAT,
            'generation' => (int) $payload['generation'],
            'ledger_session_sha256' => (string) $response['ledger_session_sha256'],
            'owner' => (string) $payload['owner'],
            'physical_erasure' => (string) $response['physical_erasure'],
            'plaintext_durable' => false,
            'prior_verifier_inputs_path' => 'artifacts/prior-verifier-inputs.json',
            'prior_verifier_inputs_sha256' => $verifierHash,
            'provider_id' => (string) $response['provider_id'],
            'provider_version' => (string) $response['provider_version'],
            'receipt_id' => (string) $payload['receipt_id'],
            'retention_until' => (string) $payload['retention_until'],
            'runtime_fingerprints_sha256' => (string) $response['runtime_fingerprints_sha256'],
            'streaming_authenticated_encryption' => true,
            'target_id' => (string) $payload['target_id'],
            'temporary_plaintext_cleaned' => true,
        ];
        self::validateMetadata($metadata);
        self::publishExact($metadataPath, CanonicalJson::encode($metadata) . "\n", 0600, 'checkpoint metadata');
        return self::publicMetadata($metadata);
    }

    /** @return array<string,mixed> */
    private static function delete(string $root, array $payload, array $status): array {
        $dir = self::receiptDirectory($root, (string) $status['receipt_id']);
        $tombstonePath = $dir . '/checkpoint-tombstone.json';
        if (is_file($tombstonePath)) {
            $tombstone = self::readCanonical($tombstonePath, 'checkpoint tombstone');
            self::validateTombstone($tombstone);
            if (!hash_equals((string) $payload['receipt_id'], (string) $tombstone['receipt_id'])
                || !hash_equals((string) $payload['target_id'], (string) $tombstone['target_id'])
                || (int) $payload['generation'] !== (int) $tombstone['generation']
                || !hash_equals((string) $payload['encryption_key_id'], (string) $tombstone['encryption_key_id'])
                || !hash_equals((string) $payload['retention_until'], (string) $tombstone['retention_until'])
                || is_file($dir . '/artifacts/checkpoint.enc')) {
                throw new \RuntimeException('duo checkpoint: deletion tombstone does not match request or ciphertext remains');
            }
            self::removeExpiredMetadata($dir);
            return $tombstone + ['ok' => true];
        }
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::assertIdentity($metadata, $status, true);
        if ((string) $payload['encryption_key_id'] !== (string) $metadata['encryption_key_id']
            || (string) $payload['retention_until'] !== (string) $metadata['retention_until']) {
            throw new \RuntimeException('duo checkpoint: deletion request changed key or retention policy');
        }
        if (time() < self::timeValue((string) $metadata['retention_until'])) {
            throw new \RuntimeException('duo checkpoint: retention window has not elapsed');
        }
        $intentPath = $dir . '/checkpoint-deletion-intent.json';
        $intent = [
            'checkpoint_metadata_sha256' => self::metadataHash($metadata),
            'format' => 'duo-checkpoint-deletion-intent/v1',
            'generation' => (int) $metadata['generation'],
            'receipt_id' => (string) $metadata['receipt_id'],
            'target_id' => (string) $metadata['target_id'],
        ];
        $cipherPresent = is_file($dir . '/' . $metadata['ciphertext_path']);
        if ($cipherPresent) {
            self::verifyArtifacts($root, $metadata);
        } elseif (!is_file($intentPath)) {
            throw new \RuntimeException('duo checkpoint: ciphertext disappeared before authorized deletion');
        }
        self::publishExact($intentPath, CanonicalJson::encode($intent) . "\n", 0600, 'checkpoint deletion intent');
        $config = RecoveryExecutor::configuration($root);
        $response = self::call($config, self::providerOperationRequest(
            $root,
            $metadata,
            $status,
            'delete',
            null,
            null
        ));
        self::assertExactKeys($response, [
            'action', 'available', 'ciphertext_absent', 'format', 'physical_erasure',
            'provider_id', 'provider_version', 'state', 'temporary_plaintext_cleaned',
        ], 'delete response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['action'] ?? '') !== 'delete'
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'deleted'
            || ($response['ciphertext_absent'] ?? null) !== true
            || ($response['temporary_plaintext_cleaned'] ?? null) !== true
            || is_file($dir . '/' . $metadata['ciphertext_path'])) {
            throw new \RuntimeException('duo checkpoint: provider did not prove ciphertext deletion');
        }
        self::assertProvider($metadata, $response);
        $tombstone = [
            'checkpoint_metadata_sha256' => self::metadataHash($metadata),
            'ciphertext_sha256' => (string) $metadata['ciphertext_sha256'],
            'deleted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'encryption_key_id' => (string) $metadata['encryption_key_id'],
            'format' => self::TOMBSTONE_FORMAT,
            'generation' => (int) $metadata['generation'],
            'physical_erasure' => (string) $response['physical_erasure'],
            'provider_id' => (string) $response['provider_id'],
            'receipt_id' => (string) $metadata['receipt_id'],
            'retention_until' => (string) $metadata['retention_until'],
            'target_id' => (string) $metadata['target_id'],
        ];
        self::publishExact($tombstonePath, CanonicalJson::encode($tombstone) . "\n", 0600, 'checkpoint tombstone');
        self::removeExpiredMetadata($dir);
        return $tombstone + ['ok' => true];
    }

    /** @return array{adapter_version:string,result_sha256:string} */
    private static function validateRestore(string $root, array $metadata, array $response, string $inputHash): array {
        self::assertExactKeys($response, [
            'abort_before', 'abort_final', 'abort_final_attempted', 'action',
            'authority_survived', 'available', 'begin_artifact_hash',
            'begin_owner', 'database_identity_sha256', 'format',
            'import_succeeded', 'input_sha256', 'provider_id',
            'provider_version', 'result_sha256', 'state',
            'temporary_plaintext_cleaned',
        ], 'restore response');
        $validBoundary = ($response['format'] ?? '') === self::PROVIDER_RESPONSE_FORMAT
            && ($response['action'] ?? '') === 'restore'
            && ($response['available'] ?? null) === true
            && ($response['abort_before'] ?? null) === true
            && ($response['abort_final_attempted'] ?? null) === true
            && ($response['abort_final'] ?? null) === true
            && ($response['authority_survived'] ?? null) === true
            && ($response['temporary_plaintext_cleaned'] ?? null) === true
            && hash_equals($inputHash, (string) ($response['input_sha256'] ?? ''))
            && hash_equals((string) $metadata['artifact_hash'], (string) ($response['begin_artifact_hash'] ?? ''))
            && hash_equals((string) $metadata['owner'], (string) ($response['begin_owner'] ?? ''))
            && hash_equals((string) $metadata['database_identity_sha256'], (string) ($response['database_identity_sha256'] ?? ''));
        self::assertProvider($metadata, $response);
        self::writeAttemptReport($root, $metadata, 'restore', $response);
        if (!$validBoundary || ($response['import_succeeded'] ?? null) !== true
            || ($response['state'] ?? '') !== 'restored') {
            throw new \RuntimeException('duo checkpoint: restore failed or did not prove final abort/authority survival');
        }
        self::assertHash((string) $response['result_sha256'], 'restore result hash');
        return ['adapter_version' => (string) $response['provider_version'], 'result_sha256' => (string) $response['result_sha256']];
    }

    /** @return array{adapter_version:string,result_sha256:string} */
    private static function validatePriorVerification(string $root, array $metadata, array $response, string $inputHash): array {
        self::assertExactKeys($response, [
            'action', 'available', 'canonical_first_sha256', 'canonical_second_sha256',
            'code_revision_sha256', 'database_identity_sha256',
            'database_schema_sha256', 'format', 'fresh_processes',
            'input_sha256', 'ledger_session_sha256',
            'lifecycle_receipts_sha256', 'live_promotion_absent',
            'manifest_inputs_sha256', 'map_state_sha256', 'policy_sha256',
            'provider_id', 'provider_version', 'result_sha256',
            'runtime_fingerprints_sha256', 'state', 'state_revision_sha256',
            'verifier_inputs_sha256',
        ], 'prior verification response');
        foreach ([
            'canonical_first_sha256', 'canonical_second_sha256', 'code_revision_sha256',
            'database_identity_sha256', 'database_schema_sha256',
            'ledger_session_sha256', 'lifecycle_receipts_sha256',
            'manifest_inputs_sha256', 'map_state_sha256', 'policy_sha256',
            'result_sha256', 'runtime_fingerprints_sha256',
            'state_revision_sha256', 'verifier_inputs_sha256',
        ] as $key) {
            self::assertHash((string) ($response[$key] ?? ''), "prior verifier $key");
        }
        self::assertProvider($metadata, $response);
        $inputs = self::readCanonical(
            self::receiptDirectory($root, (string) $metadata['receipt_id']) . '/' . $metadata['prior_verifier_inputs_path'],
            'prior verifier inputs'
        );
        self::validateVerifierInputs($inputs);
        self::writeAttemptReport($root, $metadata, 'verify-prior', $response);
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['action'] ?? '') !== 'verify-prior'
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'verified'
            || ($response['fresh_processes'] ?? null) !== true
            || ($response['live_promotion_absent'] ?? null) !== true
            || !hash_equals($inputHash, (string) $response['input_sha256'])
            || !hash_equals((string) $response['canonical_first_sha256'], (string) $response['canonical_second_sha256'])
            || !hash_equals((string) $inputs['canonical_tree_sha256'], (string) $response['canonical_first_sha256'])
            || !hash_equals((string) $metadata['database_identity_sha256'], (string) $response['database_identity_sha256'])
            || !hash_equals((string) $inputs['code_revision_sha256'], (string) $response['code_revision_sha256'])
            || !hash_equals((string) $inputs['database_schema_sha256'], (string) $response['database_schema_sha256'])
            || !hash_equals((string) $inputs['lifecycle_receipts_sha256'], (string) $response['lifecycle_receipts_sha256'])
            || !hash_equals((string) $inputs['manifest_inputs_sha256'], (string) $response['manifest_inputs_sha256'])
            || !hash_equals((string) $inputs['map_state_sha256'], (string) $response['map_state_sha256'])
            || !hash_equals((string) $inputs['policy_sha256'], (string) $response['policy_sha256'])
            || !hash_equals((string) $inputs['state_revision_sha256'], (string) $response['state_revision_sha256'])
            || !hash_equals((string) $metadata['prior_verifier_inputs_sha256'], (string) $response['verifier_inputs_sha256'])
            || !hash_equals((string) $metadata['runtime_fingerprints_sha256'], (string) $response['runtime_fingerprints_sha256'])
            || !hash_equals((string) $metadata['ledger_session_sha256'], (string) $response['ledger_session_sha256'])) {
            throw new \RuntimeException('duo checkpoint: prior verification evidence is incomplete or mismatched');
        }
        return ['adapter_version' => (string) $response['provider_version'], 'result_sha256' => (string) $response['result_sha256']];
    }

    private static function validatePrepareResponse(array $response, string $cipherPath, string $verifierPath, string $keyId): void {
        self::assertExactKeys($response, [
            'algorithm', 'available', 'ciphertext_path', 'ciphertext_sha256',
            'ciphertext_size', 'database_identity_sha256',
            'disposable_import_sha256', 'disposable_import_verified',
            'export_evidence_sha256', 'format', 'key_id', 'ledger_session_sha256',
            'physical_erasure', 'plaintext_durable', 'prior_verifier_inputs_path',
            'prior_verifier_inputs_sha256', 'provider_id', 'provider_version',
            'runtime_fingerprints_sha256', 'state',
            'streaming_authenticated_encryption', 'temporary_plaintext_cleaned',
        ], 'prepare response');
        foreach ([
            'ciphertext_sha256', 'database_identity_sha256', 'disposable_import_sha256',
            'export_evidence_sha256', 'ledger_session_sha256',
            'prior_verifier_inputs_sha256', 'runtime_fingerprints_sha256',
        ] as $key) {
            self::assertHash((string) ($response[$key] ?? ''), "prepare $key");
        }
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'prepared'
            || ($response['streaming_authenticated_encryption'] ?? null) !== true
            || ($response['plaintext_durable'] ?? null) !== false
            || ($response['temporary_plaintext_cleaned'] ?? null) !== true
            || ($response['disposable_import_verified'] ?? null) !== true
            || ($response['ciphertext_path'] ?? '') !== $cipherPath
            || ($response['prior_verifier_inputs_path'] ?? '') !== $verifierPath
            || ($response['key_id'] ?? '') !== $keyId
            || !is_int($response['ciphertext_size'] ?? null) || (int) $response['ciphertext_size'] < 1) {
            throw new \RuntimeException('duo checkpoint: prepare evidence does not prove encrypted/importable checkpoint');
        }
        foreach (['algorithm', 'physical_erasure', 'provider_id', 'provider_version'] as $key) {
            self::assertActor((string) ($response[$key] ?? ''), "prepare $key");
        }
    }

    private static function validateRequest(array $payload): void {
        self::assertExactKeys($payload, [
            'action', 'artifact_hash', 'claim_epoch', 'claimant',
            'encryption_key_id', 'format', 'generation', 'owner', 'receipt_id',
            'retention_until', 'target_id', 'timestamp',
        ], 'request payload');
        if (($payload['format'] ?? '') !== self::REQUEST_FORMAT
            || !in_array((string) ($payload['action'] ?? ''), ['delete', 'prepare'], true)) {
            throw new \RuntimeException('duo checkpoint: unsupported request format/action');
        }
        self::assertHash((string) $payload['artifact_hash'], 'request artifact hash');
        self::assertIdentifier((string) $payload['receipt_id'], 'request receipt id', 32, 64);
        self::assertIdentifier((string) $payload['target_id'], 'request target id', 32, 32);
        foreach (['claimant', 'encryption_key_id', 'owner'] as $key) {
            self::assertActor((string) $payload[$key], "request $key");
        }
        if (!is_int($payload['generation']) || $payload['generation'] < 1
            || !is_int($payload['claim_epoch']) || $payload['claim_epoch'] < 1) {
            throw new \RuntimeException('duo checkpoint: request generation/claim_epoch must be positive integers');
        }
        self::timeValue((string) $payload['timestamp']);
        self::timeValue((string) $payload['retention_until']);
        if ((string) $payload['action'] === 'prepare'
            && self::timeValue((string) $payload['retention_until']) <= self::timeValue((string) $payload['timestamp'])) {
            throw new \RuntimeException('duo checkpoint: retention must end after preparation');
        }
    }

    private static function validateMetadata(array $metadata): void {
        self::assertExactKeys($metadata, [
            'algorithm', 'artifact_hash', 'ciphertext_path', 'ciphertext_sha256',
            'ciphertext_size', 'claim_epoch', 'claimant', 'created_at',
            'database_identity_sha256', 'disposable_import_sha256',
            'encryption_key_id', 'export_evidence_sha256', 'format', 'generation',
            'ledger_session_sha256', 'owner', 'physical_erasure',
            'plaintext_durable', 'prior_verifier_inputs_path',
            'prior_verifier_inputs_sha256', 'provider_id', 'provider_version',
            'receipt_id', 'retention_until', 'runtime_fingerprints_sha256',
            'streaming_authenticated_encryption', 'target_id',
            'temporary_plaintext_cleaned',
        ], 'checkpoint metadata');
        if (($metadata['format'] ?? '') !== self::METADATA_FORMAT
            || ($metadata['ciphertext_path'] ?? '') !== 'artifacts/checkpoint.enc'
            || ($metadata['prior_verifier_inputs_path'] ?? '') !== 'artifacts/prior-verifier-inputs.json'
            || ($metadata['plaintext_durable'] ?? null) !== false
            || ($metadata['streaming_authenticated_encryption'] ?? null) !== true
            || ($metadata['temporary_plaintext_cleaned'] ?? null) !== true) {
            throw new \RuntimeException('duo checkpoint: checkpoint metadata contract is invalid');
        }
        foreach ([
            'artifact_hash', 'ciphertext_sha256', 'database_identity_sha256',
            'disposable_import_sha256', 'export_evidence_sha256',
            'ledger_session_sha256', 'prior_verifier_inputs_sha256',
            'runtime_fingerprints_sha256',
        ] as $key) {
            self::assertHash((string) $metadata[$key], "metadata $key");
        }
        foreach (['algorithm', 'claimant', 'encryption_key_id', 'owner', 'physical_erasure', 'provider_id', 'provider_version'] as $key) {
            self::assertActor((string) $metadata[$key], "metadata $key");
        }
        self::assertIdentifier((string) $metadata['receipt_id'], 'metadata receipt id', 32, 64);
        self::assertIdentifier((string) $metadata['target_id'], 'metadata target id', 32, 32);
        if (!is_int($metadata['generation']) || $metadata['generation'] < 1
            || !is_int($metadata['claim_epoch']) || $metadata['claim_epoch'] < 1
            || !is_int($metadata['ciphertext_size']) || $metadata['ciphertext_size'] < 1) {
            throw new \RuntimeException('duo checkpoint: metadata counters/size are invalid');
        }
        self::timeValue((string) $metadata['created_at']);
        self::timeValue((string) $metadata['retention_until']);
    }

    private static function validateVerifierInputs(array $inputs): void {
        self::assertExactKeys($inputs, [
            'canonical_tree_sha256', 'code_revision_sha256',
            'database_schema_sha256', 'format', 'ledger_session_sha256',
            'lifecycle_receipts_sha256', 'manifest_inputs_sha256',
            'map_state_sha256', 'policy_sha256',
            'runtime_fingerprints_sha256', 'state_revision_sha256',
        ], 'prior verifier inputs');
        if (($inputs['format'] ?? '') !== 'duo-prior-verifier-inputs/v1') {
            throw new \RuntimeException('duo checkpoint: unsupported prior verifier input format');
        }
        foreach ($inputs as $key => $value) {
            if ($key !== 'format') {
                self::assertHash((string) $value, "prior verifier input $key");
            }
        }
    }

    private static function validateTombstone(array $tombstone): void {
        self::assertExactKeys($tombstone, [
            'checkpoint_metadata_sha256', 'ciphertext_sha256', 'deleted_at',
            'encryption_key_id', 'format', 'generation', 'physical_erasure',
            'provider_id', 'receipt_id', 'retention_until', 'target_id',
        ], 'checkpoint tombstone');
        if (($tombstone['format'] ?? '') !== self::TOMBSTONE_FORMAT
            || !is_int($tombstone['generation'] ?? null) || (int) $tombstone['generation'] < 1) {
            throw new \RuntimeException('duo checkpoint: checkpoint tombstone contract is invalid');
        }
        foreach (['checkpoint_metadata_sha256', 'ciphertext_sha256'] as $key) {
            self::assertHash((string) ($tombstone[$key] ?? ''), "tombstone $key");
        }
        self::assertActor((string) ($tombstone['encryption_key_id'] ?? ''), 'tombstone encryption key id');
        self::assertActor((string) ($tombstone['physical_erasure'] ?? ''), 'tombstone physical erasure');
        self::assertActor((string) ($tombstone['provider_id'] ?? ''), 'tombstone provider id');
        self::assertIdentifier((string) ($tombstone['receipt_id'] ?? ''), 'tombstone receipt id', 32, 64);
        self::assertIdentifier((string) ($tombstone['target_id'] ?? ''), 'tombstone target id', 32, 32);
        self::timeValue((string) ($tombstone['deleted_at'] ?? ''));
        self::timeValue((string) ($tombstone['retention_until'] ?? ''));
    }

    private static function verifyArtifacts(string $root, array $metadata): void {
        $dir = self::receiptDirectory($root, (string) $metadata['receipt_id']);
        $cipher = $dir . '/' . $metadata['ciphertext_path'];
        $verifier = $dir . '/' . $metadata['prior_verifier_inputs_path'];
        self::assertAbsoluteRegularFile($cipher, 'encrypted checkpoint');
        self::assertAbsoluteRegularFile($verifier, 'prior verifier inputs');
        $inputs = self::readCanonical($verifier, 'prior verifier inputs');
        self::validateVerifierInputs($inputs);
        $cipherHash = hash_file('sha256', $cipher);
        $verifierHash = hash_file('sha256', $verifier);
        if (!is_string($cipherHash) || !is_string($verifierHash)
            || !hash_equals((string) $metadata['ciphertext_sha256'], $cipherHash)
            || !hash_equals((string) $metadata['prior_verifier_inputs_sha256'], $verifierHash)
            || filesize($cipher) !== (int) $metadata['ciphertext_size']) {
            throw new \RuntimeException('duo checkpoint: encrypted checkpoint or verifier inputs are corrupt/truncated');
        }
    }

    /** @return array<string,mixed> */
    private static function providerOperationRequest(
        string $root,
        array $metadata,
        array $status,
        string $action,
        ?string $inputPath,
        ?string $inputHash
    ): array {
        return [
            'action' => $action,
            'artifact_directory' => self::receiptDirectory($root, (string) $metadata['receipt_id']) . '/artifacts',
            'artifact_hash' => (string) $metadata['artifact_hash'],
            'checkpoint_metadata_sha256' => self::metadataHash($metadata),
            'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'],
            'database_identity_sha256' => (string) $metadata['database_identity_sha256'],
            'encryption_key_id' => (string) $metadata['encryption_key_id'],
            'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => (int) $metadata['generation'],
            'input_path' => $inputPath,
            'input_sha256' => $inputHash,
            'owner' => (string) $metadata['owner'],
            'receipt_id' => (string) $metadata['receipt_id'],
            'target_id' => (string) $metadata['target_id'],
        ];
    }

    /** @return array<string,mixed> */
    private static function publicMetadata(array $metadata): array {
        return [
            'checkpoint_sha256' => self::metadataHash($metadata),
            'ciphertext_sha256' => (string) $metadata['ciphertext_sha256'],
            'created_at' => (string) $metadata['created_at'],
            'database_identity_sha256' => (string) $metadata['database_identity_sha256'],
            'encryption_key_id' => (string) $metadata['encryption_key_id'],
            'format' => self::METADATA_FORMAT,
            'ledger_session_sha256' => (string) $metadata['ledger_session_sha256'],
            'ok' => true,
            'physical_erasure' => (string) $metadata['physical_erasure'],
            'prior_verifier_inputs_sha256' => (string) $metadata['prior_verifier_inputs_sha256'],
            'receipt_id' => (string) $metadata['receipt_id'],
            'runtime_fingerprints_sha256' => (string) $metadata['runtime_fingerprints_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private static function metadata(string $root, string $receiptId): array {
        $metadata = self::readCanonical(
            self::receiptDirectory($root, $receiptId) . '/checkpoint-metadata.json',
            'checkpoint metadata'
        );
        self::validateMetadata($metadata);
        return $metadata;
    }

    private static function metadataHash(array $metadata): string {
        return hash('sha256', CanonicalJson::encode($metadata));
    }

    private static function assertIdentity(array $left, array $right, bool $includeClaim): void {
        $keys = ['artifact_hash', 'generation', 'owner', 'receipt_id', 'target_id'];
        if ($includeClaim) {
            $keys[] = 'claim_epoch';
            $keys[] = 'claimant';
        }
        foreach ($keys as $key) {
            if ((string) ($left[$key] ?? '') !== (string) ($right[$key] ?? '')) {
                throw new \RuntimeException("duo checkpoint: $key identity mismatch");
            }
        }
    }

    private static function assertProvider(array $metadata, array $response): void {
        foreach (['provider_id', 'provider_version'] as $key) {
            self::assertActor((string) ($response[$key] ?? ''), "response $key");
            if (!hash_equals((string) $metadata[$key], (string) $response[$key])) {
                throw new \RuntimeException("duo checkpoint: $key changed after checkpoint preparation");
            }
        }
    }

    /** @template T @param callable():T $callback @return T */
    private static function withLock(string $root, callable $callback): mixed {
        $dir = dirname($root) . '/rollback';
        self::ensureDirectory($dir, 0700);
        $path = $dir . '/checkpoint.lock';
        return ProtocolLock::withExclusive(
            $path,
            $callback,
            'duo checkpoint: unsafe checkpoint lock',
            'duo checkpoint: could not open checkpoint lock',
            'duo checkpoint: could not acquire checkpoint lock',
            0600
        );
    }

    private static function writeAttemptReport(string $root, array $metadata, string $action, array $response): void {
        $dir = self::receiptDirectory($root, (string) $metadata['receipt_id']) . '/reports';
        self::ensureDirectory($dir, 0700);
        $hash = hash('sha256', CanonicalJson::encode($response));
        self::publishExact($dir . '/' . $action . '-' . $hash . '.json', CanonicalJson::encode($response) . "\n", 0600, 'checkpoint report');
    }

    private static function removeExpiredMetadata(string $dir): void {
        foreach ([
            $dir . '/artifacts/prior-verifier-inputs.json',
            $dir . '/checkpoint-metadata.json',
            $dir . '/checkpoint-deletion-intent.json',
        ] as $expired) {
            if (is_link($expired) || (file_exists($expired) && !is_file($expired))
                || (is_file($expired) && !@unlink($expired))) {
                throw new \RuntimeException('duo checkpoint: could not delete expired checkpoint metadata');
            }
        }
        self::syncDirectory($dir);
    }

    /** @return array<string,mixed> */
    private static function call(array $config, array $request): array {
        $command = $config['checkpoint_provider'] ?? null;
        if (!is_array($command)) {
            throw new \RuntimeException('duo checkpoint: checkpoint provider is unavailable');
        }
        return ProviderClient::request(
            $command,
            $request,
            (int) $config['timeout_seconds'],
            'duo checkpoint',
            'duo checkpoint: could not start checkpoint provider',
            'duo checkpoint: provider timed out; exclusion remains held',
            'duo checkpoint: provider output exceeded the redacted evidence limit',
            'duo checkpoint: provider failed; provider output is redacted',
            false,
            'duo checkpoint: provider returned malformed JSON',
            'duo checkpoint: provider returned noncanonical evidence',
            'duo checkpoint: could not send provider request'
        );
    }

    /** @return array<string,mixed> */
    private static function readCanonical(string $path, string $label): array {
        return AtomicStore::readCanonical($path, $label, 'duo checkpoint');
    }

    private static function publishExact(string $path, string $bytes, int $mode, string $label): void {
        AtomicStore::publishExact($path, $bytes, $mode, $label, 'duo checkpoint', true);
    }

    private static function ensureDirectory(string $path, int $mode): void {
        AtomicStore::ensureDirectory($path, $mode, 'duo checkpoint');
    }

    private static function syncDirectory(string $path): void {
        AtomicStore::syncDirectory($path, 'checkpoint directory', 'duo checkpoint');
    }

    private static function assertAbsoluteRegularFile(string $path, string $label): void {
        AtomicStore::assertAbsoluteRegularFile($path, $label, 'duo checkpoint');
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo checkpoint: $label has missing or unknown fields");
        }
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new \RuntimeException("duo checkpoint: $label must be a sha256 hex digest");
        }
    }

    private static function assertIdentifier(string $value, string $label, int $min, int $max): void {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new \RuntimeException("duo checkpoint: $label is malformed");
        }
    }

    private static function assertActor(string $value, string $label): void {
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9._:@+\/-]+$/', $value) !== 1) {
            throw new \RuntimeException("duo checkpoint: $label is malformed");
        }
    }

    private static function timeValue(string $value): int {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \RuntimeException('duo checkpoint: timestamp must be canonical UTC seconds');
        }
        return $time->getTimestamp();
    }

    private static function receiptDirectory(string $root, string $receiptId): string {
        self::assertIdentifier($receiptId, 'receipt id', 32, 64);
        return dirname($root) . '/rollback/' . $receiptId;
    }
}
