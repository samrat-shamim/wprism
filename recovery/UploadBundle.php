<?php
declare(strict_types=1);

namespace WPrism\Recovery;

/**
 * Receipt-bound upload/media before-images and exact inverse authority.
 *
 * A target-owned provider may implement local storage, an offload service,
 * or both. This WordPress-independent runtime freezes the desired mutation
 * roots, verifies encrypted before-image evidence, rejects unsupported
 * storage, and admits only provider operations whose per-path journal is
 * complete and bounded by the immutable compile inventory.
 */
final class UploadBundle {
    private const REQUEST_FORMAT = 'wprism-upload-bundle-request/v1';
    private const PROVIDER_REQUEST_FORMAT = 'wprism-upload-provider-request/v1';
    private const PROVIDER_RESPONSE_FORMAT = 'wprism-upload-provider-response/v1';
    private const METADATA_FORMAT = 'wprism-upload-bundle-metadata/v1';
    private const INVENTORY_FORMAT = 'wprism-upload-prior-inventory/v1';
    private const OPERATION_FORMAT = 'wprism-upload-operation/v1';
    private const REPORT_FORMAT = 'wprism-upload-mutation-report/v1';
    private const TOMBSTONE_FORMAT = 'wprism-upload-bundle-tombstone/v1';

    public static function configured(string $root): bool {
        return array_key_exists('upload_provider', RecoveryExecutor::configuration($root));
    }

    /** @return array<string,mixed> */
    public static function probe(string $root): array {
        $config = RecoveryExecutor::configuration($root);
        if (!array_key_exists('upload_provider', $config)) {
            throw new \RuntimeException('wprism uploads: no upload provider is configured');
        }
        $status = RollbackControl::status($root);
        $response = self::call($config, [
            'action' => 'probe', 'artifact_directory' => null, 'artifact_hash' => null,
            'claim_epoch' => null, 'claimant' => null, 'desired_inventory_path' => null,
            'desired_inventory_sha256' => null, 'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => (int) $status['generation'], 'input_path' => null,
            'input_sha256' => null, 'metadata_sha256' => null, 'owner' => null,
            'prior_inventory_path' => null, 'prior_inventory_sha256' => null,
            'receipt_id' => null, 'target_id' => (string) $status['target_id'],
        ]);
        self::assertExactKeys($response, [
            'authenticated_encryption', 'available', 'credentials_exposed', 'encrypted_before_images', 'format',
            'plaintext_durable', 'provider_id', 'provider_version', 'read_after_restore',
            'state', 'supports_local', 'supports_offload',
        ], 'probe response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'ready'
            || ($response['authenticated_encryption'] ?? null) !== true
            || ($response['encrypted_before_images'] ?? null) !== true
            || ($response['plaintext_durable'] ?? null) !== false
            || ($response['read_after_restore'] ?? null) !== true
            || ($response['credentials_exposed'] ?? null) !== false
            || (($response['supports_local'] ?? false) !== true
                && ($response['supports_offload'] ?? false) !== true)) {
            throw new \RuntimeException('wprism uploads: provider did not attest a reversible, encrypted storage contract');
        }
        self::assertActor((string) ($response['provider_id'] ?? ''), 'provider id');
        self::assertActor((string) ($response['provider_version'] ?? ''), 'provider version');
        return ['ok' => true] + $response;
    }

    /** @return array<string,mixed> */
    public static function handleRequest(string $root, string $requestPath): array {
        self::assertAbsoluteRegularFile($requestPath, 'signed request');
        $payload = RollbackControl::verifyEnvelope(
            $root,
            self::readCanonical($requestPath, 'signed request'),
            'upload bundle request'
        );
        self::validateRequest($payload);
        return self::withLock($root, function () use ($root, $payload): array {
            $status = RollbackControl::status($root);
            if ((string) $payload['action'] === 'prepare') {
                if (!empty($status['active']) && empty($status['terminal'])) {
                    throw new \RuntimeException('wprism uploads: prepare refused during a nonterminal generation');
                }
                if ((int) $payload['generation'] !== (int) $status['generation'] + 1
                    || (int) $payload['claim_epoch'] !== 1
                    || !hash_equals((string) $payload['target_id'], (string) $status['target_id'])) {
                    throw new \RuntimeException('wprism uploads: prepare is not for the exact next target generation');
                }
                $recovery = RecoveryExecutor::decorateStatus($root, $status);
                $reservation = $recovery['exclusion_reservation'] ?? null;
                if (($recovery['exclusion_state'] ?? '') !== 'held' || !is_array($reservation)) {
                    throw new \RuntimeException('wprism uploads: prepare requires verified maintenance exclusion');
                }
                foreach (['artifact_hash', 'claim_epoch', 'claimant', 'generation', 'owner', 'receipt_id'] as $key) {
                    if ((string) $payload[$key] !== (string) ($reservation[$key] ?? '')) {
                        throw new \RuntimeException("wprism uploads: exclusion reservation $key does not match prepare");
                    }
                }
                return self::prepare($root, $payload);
            }
            if (empty($status['active']) || empty($status['terminal'])) {
                throw new \RuntimeException('wprism uploads: deletion requires signed terminal authority');
            }
            self::assertIdentity($payload, $status, true);
            return self::delete($root, $payload, $status);
        });
    }

    /** Called inside the target authority lock before receipt publication. */
    public static function assertClaimUploadBundle(string $root, array $receipt, array $event): void {
        $metadata = self::metadata($root, (string) $receipt['receipt_id']);
        self::assertIdentity($metadata, $receipt, false);
        if ((string) $metadata['claimant'] !== (string) $event['claimant']
            || (int) $metadata['claim_epoch'] !== (int) $event['claim_epoch']
            || !hash_equals(self::metadataHash($metadata), (string) $receipt['uploads_inventory_sha256'])
            || (string) $metadata['created_at'] !== (string) $receipt['created_at']
            || (string) $metadata['retention_until'] !== (string) $receipt['retention_until']) {
            throw new \RuntimeException('wprism uploads: prepared bundle is not bound to the exact claim receipt');
        }
        self::verifyArtifacts($root, $metadata);
    }

    /** @return array{adapter_version:string,result_sha256:string} */
    public static function execute(
        string $root,
        string $adapter,
        string $inputPath,
        string $inputHash,
        array $status
    ): array {
        if (!in_array($adapter, ['storage_apply', 'storage_restore'], true)) {
            throw new \RuntimeException('wprism uploads: unsupported upload operation');
        }
        $input = self::readCanonical($inputPath, 'operation input');
        self::assertExactKeys($input, [
            'artifact_hash', 'desired_inventory_sha256', 'format', 'generation',
            'operation', 'owner', 'prior_inventory_sha256', 'receipt_id',
            'target_id', 'uploads_inventory_sha256',
        ], 'operation input');
        $operation = $adapter === 'storage_apply' ? 'apply_desired' : 'restore_prior';
        if (($input['format'] ?? '') !== self::OPERATION_FORMAT || ($input['operation'] ?? '') !== $operation) {
            throw new \RuntimeException('wprism uploads: operation input does not name the authorized transition');
        }
        self::assertIdentity($input, $status, false);
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::assertIdentity($metadata, $status, false);
        self::verifyArtifacts($root, $metadata);
        foreach (['desired_inventory_sha256', 'prior_inventory_sha256'] as $key) {
            if (!hash_equals((string) $metadata[$key], (string) $input[$key])) {
                throw new \RuntimeException("wprism uploads: operation $key does not match immutable evidence");
            }
        }
        if (!hash_equals(self::metadataHash($metadata), (string) $input['uploads_inventory_sha256'])
            || !hash_equals(self::metadataHash($metadata), (string) $status['uploads_inventory_sha256'])) {
            throw new \RuntimeException('wprism uploads: signed receipt does not authorize this upload bundle');
        }
        $config = RecoveryExecutor::configuration($root);
        $request = self::providerOperationRequest($root, $metadata, $status, $operation, $inputPath, $inputHash);
        $response = self::call($config, $request);
        if ($adapter === 'storage_apply') {
            return self::validateApply($root, $metadata, $response);
        }
        self::validateRestoreResponse($metadata, $response, 'restored');
        // A second provider process performs the required read-after-restore
        // inventory. Success from the mutating call alone is never proof.
        $verify = $request;
        $verify['action'] = 'verify-prior';
        $verified = self::call($config, $verify);
        self::validateRestoreResponse($metadata, $verified, 'verified');
        if (!hash_equals((string) $response['result_sha256'], (string) $verified['result_sha256'])) {
            throw new \RuntimeException('wprism uploads: restored inventory changed during fresh verification');
        }
        return [
            'adapter_version' => (string) $verified['provider_version'],
            'result_sha256' => (string) $verified['result_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    public static function statusEvidence(string $root, string $receiptId): array {
        $tombstonePath = self::receiptDirectory($root, $receiptId) . '/upload-bundle-tombstone.json';
        if (is_file($tombstonePath)) {
            $tombstone = self::readCanonical($tombstonePath, 'upload bundle tombstone');
            self::assertExactKeys($tombstone, [
                'deleted_at', 'desired_inventory_sha256', 'format', 'metadata_sha256',
                'prior_inventory_sha256', 'provider_id', 'provider_version', 'receipt_id',
            ], 'upload bundle tombstone');
            if (($tombstone['format'] ?? '') !== self::TOMBSTONE_FORMAT
                || !hash_equals($receiptId, (string) ($tombstone['receipt_id'] ?? ''))) {
                throw new \RuntimeException('wprism uploads: deletion tombstone is stale or corrupt');
            }
            foreach (['desired_inventory_sha256', 'metadata_sha256', 'prior_inventory_sha256'] as $key) {
                self::assertHash((string) ($tombstone[$key] ?? ''), "tombstone $key");
            }
            self::assertActor((string) ($tombstone['provider_id'] ?? ''), 'tombstone provider id');
            self::assertActor((string) ($tombstone['provider_version'] ?? ''), 'tombstone provider version');
            self::timeValue((string) ($tombstone['deleted_at'] ?? ''));
            return [
                'deleted' => true,
                'desired_inventory_sha256' => (string) $tombstone['desired_inventory_sha256'],
                'metadata_sha256' => (string) $tombstone['metadata_sha256'],
                'prior_inventory_sha256' => (string) $tombstone['prior_inventory_sha256'],
                'provider_id' => (string) $tombstone['provider_id'],
                'provider_version' => (string) $tombstone['provider_version'],
            ];
        }
        $metadata = self::metadata($root, $receiptId);
        self::verifyArtifacts($root, $metadata);
        return [
            'desired_inventory_sha256' => (string) $metadata['desired_inventory_sha256'],
            'metadata_sha256' => self::metadataHash($metadata),
            'prior_inventory_sha256' => (string) $metadata['prior_inventory_sha256'],
            'provider_id' => (string) $metadata['provider_id'],
            'provider_version' => (string) $metadata['provider_version'],
        ];
    }

    /** @return array<string,mixed> */
    private static function prepare(string $root, array $payload): array {
        $dir = self::receiptDirectory($root, (string) $payload['receipt_id']);
        self::ensureDirectory($dir, 0700);
        self::ensureDirectory($dir . '/artifacts', 0700);
        $metadataPath = $dir . '/upload-bundle-metadata.json';
        $desired = ['format' => 'wprism-upload-compile-inventory/v1', 'uploads' => $payload['inventory']];
        self::validateDesiredInventory($desired);
        $desiredBytes = CanonicalJson::encode($desired) . "\n";
        $desiredHash = hash('sha256', $desiredBytes);
        if (is_file($metadataPath)) {
            $metadata = self::readCanonical($metadataPath, 'upload bundle metadata');
            self::validateMetadata($metadata);
            self::assertIdentity($payload, $metadata, true);
            if (!hash_equals($desiredHash, (string) $metadata['desired_inventory_sha256'])
                || (string) $payload['retention_until'] !== (string) $metadata['retention_until']) {
                throw new \RuntimeException('wprism uploads: prepare retry changed immutable inputs');
            }
            self::verifyArtifacts($root, $metadata);
            return self::publicMetadata($metadata);
        }
        $desiredPath = $dir . '/artifacts/desired-inventory.json';
        $priorPath = $dir . '/artifacts/prior-inventory.json';
        $cipherPath = $dir . '/artifacts/before-images.enc';
        self::publishExact($desiredPath, $desiredBytes, 0600, 'desired inventory');
        foreach ([$priorPath, $cipherPath] as $path) {
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('wprism uploads: prepare output path is unsafe');
            }
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('wprism uploads: could not clear interrupted provider output');
            }
        }
        self::syncDirectory($dir . '/artifacts');
        $config = RecoveryExecutor::configuration($root);
        $response = self::call($config, [
            'action' => 'prepare', 'artifact_directory' => $dir . '/artifacts',
            'artifact_hash' => (string) $payload['artifact_hash'], 'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'], 'desired_inventory_path' => $desiredPath,
            'desired_inventory_sha256' => $desiredHash, 'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => (int) $payload['generation'], 'input_path' => null, 'input_sha256' => null,
            'metadata_sha256' => null, 'owner' => (string) $payload['owner'],
            'prior_inventory_path' => $priorPath, 'prior_inventory_sha256' => null,
            'receipt_id' => (string) $payload['receipt_id'], 'target_id' => (string) $payload['target_id'],
        ]);
        self::assertExactKeys($response, [
            'authenticated_encryption', 'before_images_sha256', 'before_images_size', 'credentials_exposed',
            'desired_inventory_sha256', 'encrypted_before_images', 'format',
            'plaintext_durable', 'prior_inventory_sha256', 'provider_id',
            'provider_version', 'read_after_restore', 'state', 'unsupported_paths',
        ], 'prepare response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== 'prepared'
            || ($response['authenticated_encryption'] ?? null) !== true
            || ($response['encrypted_before_images'] ?? null) !== true
            || ($response['plaintext_durable'] ?? null) !== false
            || ($response['read_after_restore'] ?? null) !== true
            || ($response['credentials_exposed'] ?? null) !== false
            || ($response['unsupported_paths'] ?? null) !== []
            || !hash_equals($desiredHash, (string) ($response['desired_inventory_sha256'] ?? ''))) {
            throw new \RuntimeException('wprism uploads: provider could not prepare every declared upload before mutation');
        }
        self::assertAbsoluteRegularFile($priorPath, 'prior inventory');
        self::assertAbsoluteRegularFile($cipherPath, 'encrypted before-images');
        $prior = self::readCanonical($priorPath, 'prior inventory');
        self::validatePriorInventory($prior, $desired);
        $priorHash = hash_file('sha256', $priorPath);
        $cipherHash = hash_file('sha256', $cipherPath);
        $cipherSize = filesize($cipherPath);
        if (!is_string($priorHash) || !is_string($cipherHash) || !is_int($cipherSize) || $cipherSize < 1
            || !hash_equals($priorHash, (string) $response['prior_inventory_sha256'])
            || !hash_equals($cipherHash, (string) $response['before_images_sha256'])
            || $cipherSize !== (int) $response['before_images_size']) {
            throw new \RuntimeException('wprism uploads: provider evidence does not match prepared artifacts');
        }
        @chmod($priorPath, 0600);
        @chmod($cipherPath, 0600);
        $metadata = [
            'artifact_hash' => (string) $payload['artifact_hash'],
            'before_images_path' => 'artifacts/before-images.enc', 'before_images_sha256' => $cipherHash,
            'before_images_size' => $cipherSize, 'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'], 'created_at' => (string) $payload['timestamp'],
            'desired_inventory_path' => 'artifacts/desired-inventory.json',
            'desired_inventory_sha256' => $desiredHash, 'format' => self::METADATA_FORMAT,
            'generation' => (int) $payload['generation'], 'owner' => (string) $payload['owner'],
            'prior_inventory_path' => 'artifacts/prior-inventory.json',
            'prior_inventory_sha256' => $priorHash, 'provider_id' => (string) $response['provider_id'],
            'provider_version' => (string) $response['provider_version'],
            'receipt_id' => (string) $payload['receipt_id'], 'retention_until' => (string) $payload['retention_until'],
            'target_id' => (string) $payload['target_id'],
        ];
        self::validateMetadata($metadata);
        self::publishExact($metadataPath, CanonicalJson::encode($metadata) . "\n", 0600, 'upload bundle metadata');
        return self::publicMetadata($metadata);
    }

    /** @return array{adapter_version:string,result_sha256:string} */
    private static function validateApply(string $root, array $metadata, array $response): array {
        self::assertExactKeys($response, [
            'credentials_exposed', 'format', 'journal_sha256', 'provider_id',
            'provider_version', 'report_path', 'result_sha256', 'state', 'undeclared_paths',
        ], 'apply response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== 'applied'
            || ($response['credentials_exposed'] ?? null) !== false
            || ($response['undeclared_paths'] ?? null) !== []) {
            throw new \RuntimeException('wprism uploads: provider reported an incomplete or undeclared mutation');
        }
        self::assertProviderIdentity($metadata, $response);
        $path = self::providerArtifactPath($root, $metadata, (string) ($response['report_path'] ?? ''), 'mutation report');
        $report = self::readCanonical($path, 'mutation report');
        if (!hash_equals((string) ($response['journal_sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new \RuntimeException('wprism uploads: mutation report hash mismatch');
        }
        self::validateMutationReport($report, self::desired($root, $metadata));
        self::rejectSecrets($report, 'mutation report');
        $result = hash('sha256', CanonicalJson::encode($report));
        if (!hash_equals($result, (string) ($response['result_sha256'] ?? ''))) {
            throw new \RuntimeException('wprism uploads: provider result does not bind the verified mutation report');
        }
        return ['adapter_version' => (string) $response['provider_version'], 'result_sha256' => $result];
    }

    private static function validateRestoreResponse(array $metadata, array $response, string $state): void {
        self::assertExactKeys($response, [
            'credentials_exposed', 'exact_absence_guard', 'format', 'prior_inventory_sha256',
            'provider_id', 'provider_version', 'read_after_restore', 'result_sha256', 'state',
        ], "$state response");
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== $state
            || ($response['credentials_exposed'] ?? null) !== false
            || ($response['exact_absence_guard'] ?? null) !== true
            || ($response['read_after_restore'] ?? null) !== true
            || !hash_equals((string) $metadata['prior_inventory_sha256'], (string) ($response['prior_inventory_sha256'] ?? ''))
            || !hash_equals((string) $metadata['prior_inventory_sha256'], (string) ($response['result_sha256'] ?? ''))) {
            throw new \RuntimeException("wprism uploads: $state response did not prove the exact prior inventory");
        }
        self::assertProviderIdentity($metadata, $response);
    }

    /** @return array<string,mixed> */
    private static function delete(string $root, array $payload, array $status): array {
        $dir = self::receiptDirectory($root, (string) $status['receipt_id']);
        $tombstonePath = $dir . '/upload-bundle-tombstone.json';
        if (is_file($tombstonePath)) {
            $existing = self::readCanonical($tombstonePath, 'upload bundle tombstone');
            if (($existing['format'] ?? '') !== self::TOMBSTONE_FORMAT
                || !hash_equals((string) ($existing['receipt_id'] ?? ''), (string) $status['receipt_id'])) {
                throw new \RuntimeException('wprism uploads: deletion tombstone is stale or corrupt');
            }
            self::cleanupDeletedBundle($dir);
            return ['deleted' => true, 'ok' => true, 'receipt_id' => (string) $status['receipt_id']];
        }
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::verifyArtifacts($root, $metadata);
        if (self::timeValue((string) $payload['timestamp']) < self::timeValue((string) $metadata['retention_until'])) {
            throw new \RuntimeException('wprism uploads: retention window has not elapsed');
        }
        $config = RecoveryExecutor::configuration($root);
        $response = self::call($config, self::providerOperationRequest($root, $metadata, $status, 'delete', null, null));
        self::assertExactKeys($response, ['credentials_exposed', 'format', 'provider_id', 'provider_version', 'state'], 'delete response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== 'deleted'
            || ($response['credentials_exposed'] ?? null) !== false) {
            throw new \RuntimeException('wprism uploads: provider did not attest bundle deletion');
        }
        $tombstone = [
            'deleted_at' => (string) $payload['timestamp'], 'format' => self::TOMBSTONE_FORMAT,
            'desired_inventory_sha256' => (string) $metadata['desired_inventory_sha256'],
            'metadata_sha256' => self::metadataHash($metadata),
            'prior_inventory_sha256' => (string) $metadata['prior_inventory_sha256'],
            'provider_id' => (string) $metadata['provider_id'],
            'provider_version' => (string) $metadata['provider_version'],
            'receipt_id' => (string) $status['receipt_id'],
        ];
        self::publishExact($tombstonePath, CanonicalJson::encode($tombstone) . "\n", 0600, 'upload bundle tombstone');
        self::cleanupDeletedBundle($dir);
        return ['deleted' => true, 'ok' => true, 'receipt_id' => (string) $status['receipt_id']];
    }

    private static function cleanupDeletedBundle(string $dir): void {
        foreach ([
            $dir . '/artifacts/before-images.enc', $dir . '/artifacts/desired-inventory.json',
            $dir . '/artifacts/prior-inventory.json', $dir . '/artifacts/provider-journal.json',
            $dir . '/artifacts/mutation-report.json', $dir . '/upload-bundle-metadata.json',
        ] as $path) {
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('wprism uploads: retained deletion path is unsafe');
            }
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('wprism uploads: could not delete retained artifact');
            }
        }
        self::syncDirectory($dir);
    }

    /** @return array<string,mixed> */
    private static function providerOperationRequest(string $root, array $metadata, array $status, string $action, ?string $inputPath, ?string $inputHash): array {
        $dir = self::receiptDirectory($root, (string) $status['receipt_id']);
        return [
            'action' => $action, 'artifact_directory' => $dir . '/artifacts',
            'artifact_hash' => (string) $status['artifact_hash'], 'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'],
            'desired_inventory_path' => self::artifactPath($root, $metadata, (string) $metadata['desired_inventory_path']),
            'desired_inventory_sha256' => (string) $metadata['desired_inventory_sha256'],
            'format' => self::PROVIDER_REQUEST_FORMAT, 'generation' => (int) $status['generation'],
            'input_path' => $inputPath, 'input_sha256' => $inputHash,
            'metadata_sha256' => self::metadataHash($metadata), 'owner' => (string) $status['owner'],
            'prior_inventory_path' => self::artifactPath($root, $metadata, (string) $metadata['prior_inventory_path']),
            'prior_inventory_sha256' => (string) $metadata['prior_inventory_sha256'],
            'receipt_id' => (string) $status['receipt_id'], 'target_id' => (string) $status['target_id'],
        ];
    }

    /** @return array<string,mixed> */
    private static function metadata(string $root, string $receiptId): array {
        $path = self::receiptDirectory($root, $receiptId) . '/upload-bundle-metadata.json';
        $metadata = self::readCanonical($path, 'upload bundle metadata');
        self::validateMetadata($metadata);
        return $metadata;
    }

    private static function verifyArtifacts(string $root, array $metadata): void {
        foreach ([
            'desired_inventory_path' => 'desired_inventory_sha256',
            'prior_inventory_path' => 'prior_inventory_sha256',
            'before_images_path' => 'before_images_sha256',
        ] as $pathKey => $hashKey) {
            $path = self::artifactPath($root, $metadata, (string) $metadata[$pathKey]);
            self::assertAbsoluteRegularFile($path, str_replace('_', ' ', $pathKey));
            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals((string) $metadata[$hashKey], $actual)) {
                throw new \RuntimeException("wprism uploads: $pathKey no longer verifies");
            }
        }
        $cipher = self::artifactPath($root, $metadata, (string) $metadata['before_images_path']);
        if (filesize($cipher) !== (int) $metadata['before_images_size']) {
            throw new \RuntimeException('wprism uploads: encrypted before-image size changed');
        }
        $desired = self::desired($root, $metadata);
        $prior = self::readCanonical(self::artifactPath($root, $metadata, (string) $metadata['prior_inventory_path']), 'prior inventory');
        self::validatePriorInventory($prior, $desired);
    }

    /** @return array<string,mixed> */
    private static function desired(string $root, array $metadata): array {
        $value = self::readCanonical(self::artifactPath($root, $metadata, (string) $metadata['desired_inventory_path']), 'desired inventory');
        self::validateDesiredInventory($value);
        return $value;
    }

    private static function validateDesiredInventory(array $inventory): void {
        self::assertExactKeys($inventory, ['format', 'uploads'], 'desired inventory');
        if (($inventory['format'] ?? '') !== 'wprism-upload-compile-inventory/v1' || !is_array($inventory['uploads'] ?? null)) {
            throw new \RuntimeException('wprism uploads: malformed desired inventory');
        }
        $seen = [];
        foreach ($inventory['uploads'] as $row) {
            if (!is_array($row) || array_is_list($row)) throw new \RuntimeException('wprism uploads: malformed desired upload row');
            self::assertExactKeys($row, ['attachment_uuid', 'derivative_basename_prefix', 'derivative_directory', 'media_blob', 'original_path', 'original_sha256'], 'desired upload row');
            self::assertSafePath((string) $row['original_path']);
            self::assertSafeDirectory((string) $row['derivative_directory']);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $row['attachment_uuid']) !== 1) throw new \RuntimeException('wprism uploads: malformed attachment UUID');
            $expectedDirectory = dirname((string) $row['original_path']);
            if ($expectedDirectory === '.') $expectedDirectory = '';
            $expectedPrefix = pathinfo(basename((string) $row['original_path']), PATHINFO_FILENAME) . '-';
            if ((string) $row['derivative_directory'] !== $expectedDirectory
                || (string) $row['derivative_basename_prefix'] !== $expectedPrefix
                || str_contains((string) $row['derivative_basename_prefix'], '\\')
                || preg_match('/[\x00-\x1f\x7f]/', (string) $row['derivative_basename_prefix']) === 1) {
                throw new \RuntimeException('wprism uploads: derivative root does not match its original path');
            }
            self::assertHash((string) $row['original_sha256'], 'desired original hash');
            if (preg_match('/^([0-9a-f]{64})\.[A-Za-z0-9]+$/', (string) $row['media_blob'], $media) !== 1
                || !hash_equals((string) $row['original_sha256'], (string) $media[1])) {
                throw new \RuntimeException('wprism uploads: media blob is not content-addressed by the desired original hash');
            }
            if (isset($seen[$row['original_path']])) throw new \RuntimeException('wprism uploads: duplicate desired original path');
            $seen[$row['original_path']] = true;
        }
    }

    private static function validatePriorInventory(array $prior, array $desired): void {
        self::assertExactKeys($prior, ['entries', 'format'], 'prior inventory');
        if (($prior['format'] ?? '') !== self::INVENTORY_FORMAT || !is_array($prior['entries'] ?? null)) throw new \RuntimeException('wprism uploads: malformed prior inventory');
        $declared = [];
        foreach ($desired['uploads'] as $row) $declared[(string) $row['original_path']] = $row;
        $seen = [];
        foreach ($prior['entries'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) throw new \RuntimeException('wprism uploads: malformed prior inventory entry');
            self::assertExactKeys($entry, ['ciphertext_ref', 'kind', 'path', 'sha256', 'state', 'version_id'], 'prior inventory entry');
            $path = (string) $entry['path'];
            self::assertSafePath($path);
            if (isset($seen[$path]) || !self::pathDeclared($path, $declared)) throw new \RuntimeException('wprism uploads: prior inventory contains duplicate or undeclared path');
            $seen[$path] = true;
            if (!in_array($entry['kind'] ?? null, ['local', 'offload'], true) || !in_array($entry['state'] ?? null, ['absent', 'present'], true)) throw new \RuntimeException('wprism uploads: unsupported prior storage entry');
            if ($entry['state'] === 'absent') {
                if ($entry['sha256'] !== null || $entry['ciphertext_ref'] !== null || $entry['version_id'] !== null) throw new \RuntimeException('wprism uploads: absence receipt contains deletion authority beyond exact absence');
            } else {
                self::assertHash((string) $entry['sha256'], 'prior path hash');
                $hasCipher = is_string($entry['ciphertext_ref']) && $entry['ciphertext_ref'] !== '';
                $hasVersion = is_string($entry['version_id']) && $entry['version_id'] !== '';
                if (!$hasCipher && !$hasVersion) throw new \RuntimeException('wprism uploads: present path lacks a before-image or provider-native version id');
                if ($entry['kind'] === 'local' && !$hasCipher) throw new \RuntimeException('wprism uploads: local path lacks encrypted before-image evidence');
            }
        }
        foreach (array_keys($declared) as $path) if (!isset($seen[$path])) throw new \RuntimeException("wprism uploads: original '$path' lacks exact prior evidence");
    }

    private static function validateMutationReport(array $report, array $desired): void {
        self::assertExactKeys($report, ['format', 'operations'], 'mutation report');
        if (($report['format'] ?? '') !== self::REPORT_FORMAT || !is_array($report['operations'] ?? null)) throw new \RuntimeException('wprism uploads: malformed mutation report');
        $declared = [];
        foreach ($desired['uploads'] as $row) $declared[(string) $row['original_path']] = $row;
        $seen = [];
        foreach ($report['operations'] as $op) {
            if (!is_array($op) || array_is_list($op)) throw new \RuntimeException('wprism uploads: malformed mutation event');
            self::assertExactKeys($op, ['action', 'after_sha256', 'before_sha256', 'completed', 'path', 'prepared'], 'mutation event');
            $path = (string) $op['path'];
            self::assertSafePath($path);
            if (isset($seen[$path]) || !self::pathDeclared($path, $declared) || ($op['prepared'] ?? null) !== true || ($op['completed'] ?? null) !== true || !in_array($op['action'] ?? null, ['publish', 'remove', 'replace'], true)) throw new \RuntimeException('wprism uploads: duplicate, undeclared, or incomplete mutation event');
            $seen[$path] = true;
            foreach (['before_sha256', 'after_sha256'] as $key) if ($op[$key] !== null) self::assertHash((string) $op[$key], "mutation $key");
            if (isset($declared[$path]) && $op['action'] === 'remove') throw new \RuntimeException('wprism uploads: desired original may not be removed');
            if (isset($declared[$path]) && !hash_equals((string) $declared[$path]['original_sha256'], (string) ($op['after_sha256'] ?? ''))) throw new \RuntimeException('wprism uploads: original publish hash differs from compiled media');
        }
        foreach (array_keys($declared) as $original) {
            if (!isset($seen[$original])) {
                throw new \RuntimeException("wprism uploads: original '$original' lacks prepared/completed readback evidence");
            }
        }
    }

    /** @param array<string,array<string,string>> $declared */
    private static function pathDeclared(string $path, array $declared): bool {
        if (isset($declared[$path])) return true;
        foreach ($declared as $row) {
            $prefix = (($row['derivative_directory'] ?? '') !== '' ? $row['derivative_directory'] . '/' : '') . $row['derivative_basename_prefix'];
            if (str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)) return true;
        }
        return false;
    }

    private static function validateRequest(array $payload): void {
        self::assertExactKeys($payload, ['action', 'artifact_hash', 'claim_epoch', 'claimant', 'format', 'generation', 'inventory', 'owner', 'receipt_id', 'retention_until', 'target_id', 'timestamp'], 'request');
        if (($payload['format'] ?? '') !== self::REQUEST_FORMAT || !in_array($payload['action'] ?? null, ['prepare', 'delete'], true)) throw new \RuntimeException('wprism uploads: unsupported request');
        self::assertHash((string) $payload['artifact_hash'], 'artifact hash');
        self::assertActor((string) $payload['owner'], 'owner');
        self::assertActor((string) $payload['claimant'], 'claimant');
        self::assertIdentifier((string) $payload['receipt_id'], 'receipt id', 32, 64);
        self::assertIdentifier((string) $payload['target_id'], 'target id', 32, 32);
        if (!is_int($payload['generation']) || $payload['generation'] < 1 || !is_int($payload['claim_epoch']) || $payload['claim_epoch'] < 1) throw new \RuntimeException('wprism uploads: generation/claim_epoch must be positive integers');
        self::timeValue((string) $payload['timestamp']);
        self::timeValue((string) $payload['retention_until']);
        if ($payload['action'] === 'prepare'
            && self::timeValue((string) $payload['retention_until']) <= self::timeValue((string) $payload['timestamp'])) {
            throw new \RuntimeException('wprism uploads: retention deadline must follow preparation');
        }
        if ($payload['action'] === 'prepare' && !is_array($payload['inventory'])) throw new \RuntimeException('wprism uploads: prepare requires compiled inventory');
        if ($payload['action'] === 'delete' && $payload['inventory'] !== null) throw new \RuntimeException('wprism uploads: delete inventory must be null');
    }

    private static function validateMetadata(array $m): void {
        self::assertExactKeys($m, ['artifact_hash','before_images_path','before_images_sha256','before_images_size','claim_epoch','claimant','created_at','desired_inventory_path','desired_inventory_sha256','format','generation','owner','prior_inventory_path','prior_inventory_sha256','provider_id','provider_version','receipt_id','retention_until','target_id'], 'metadata');
        if (($m['format'] ?? '') !== self::METADATA_FORMAT || !is_int($m['before_images_size'] ?? null) || $m['before_images_size'] < 1) throw new \RuntimeException('wprism uploads: malformed metadata');
        foreach (['artifact_hash','before_images_sha256','desired_inventory_sha256','prior_inventory_sha256'] as $key) self::assertHash((string) $m[$key], "metadata $key");
        self::assertActor((string) $m['provider_id'], 'provider id');
        self::assertActor((string) $m['provider_version'], 'provider version');
        self::timeValue((string) $m['created_at']);
        self::timeValue((string) $m['retention_until']);
        self::rejectSecrets($m, 'metadata');
    }

    private static function assertIdentity(array $a, array $b, bool $claimant): void {
        foreach (['artifact_hash','generation','owner','receipt_id','target_id'] as $key) if ((string) ($a[$key] ?? '') !== (string) ($b[$key] ?? '')) throw new \RuntimeException("wprism uploads: identity $key mismatch");
        if ($claimant && ((string) ($a['claimant'] ?? '') !== (string) ($b['claimant'] ?? '') || (int) ($a['claim_epoch'] ?? 0) !== (int) ($b['claim_epoch'] ?? 0))) throw new \RuntimeException('wprism uploads: claimant identity mismatch');
    }

    private static function assertProviderIdentity(array $metadata, array $response): void {
        if (!hash_equals((string) $metadata['provider_id'], (string) ($response['provider_id'] ?? ''))
            || !hash_equals((string) $metadata['provider_version'], (string) ($response['provider_version'] ?? ''))) {
            throw new \RuntimeException('wprism uploads: provider identity changed after preparation');
        }
    }

    /** @return array<string,mixed> */
    private static function publicMetadata(array $m): array { return ['before_images_sha256' => $m['before_images_sha256'],'desired_inventory_sha256' => $m['desired_inventory_sha256'],'ok' => true,'prior_inventory_sha256' => $m['prior_inventory_sha256'],'provider_id' => $m['provider_id'],'provider_version' => $m['provider_version'],'uploads_inventory_sha256' => self::metadataHash($m)]; }
    private static function metadataHash(array $m): string { return hash('sha256', CanonicalJson::encode($m)); }

    /** @return array<string,mixed> */
    private static function call(array $config, array $request): array {
        $command = $config['upload_provider'] ?? null;
        if (!is_array($command) || $command === []) throw new \RuntimeException('wprism uploads: provider is unavailable');
        $decoded = ProviderClient::request(
            $command,
            $request,
            (int) $config['timeout_seconds'],
            'wprism uploads',
            'wprism uploads: could not start provider',
            'wprism uploads: provider timed out; exclusion remains held',
            'wprism uploads: provider output exceeded redacted limit',
            'wprism uploads: provider failed; provider output is redacted',
            false,
            'wprism uploads: provider returned malformed JSON',
            'wprism uploads: provider returned noncanonical evidence'
        );
        self::rejectSecrets($decoded, 'provider response');
        return $decoded;
    }

    private static function rejectSecrets(mixed $value, string $label, string $key = ''): void {
        if (preg_match('/secret|credential|password|token|signed.?url|authorization/i', $key) === 1 && !in_array($key, ['credentials_exposed'], true)) throw new \RuntimeException("wprism uploads: $label contains forbidden secret field");
        if (is_string($value) && preg_match('#https?://[^\s]*[?&](?:X-Amz-|Signature=|token=)#i', $value) === 1) throw new \RuntimeException("wprism uploads: $label contains a signed URL");
        if (is_array($value)) foreach ($value as $k => $v) self::rejectSecrets($v, $label, (string)$k);
    }
    private static function providerArtifactPath(string $root, array $m, string $path, string $label): string { if ($path === '' || str_contains($path, '/') || str_contains($path, '\\'))throw new \RuntimeException("wprism uploads: unsafe $label path");
    return self::receiptDirectory($root, (string)$m['receipt_id']).'/artifacts/'.$path; }
    private static function artifactPath(string $root, array $m, string $relative): string { if (!preg_match('#^artifacts/[A-Za-z0-9._-]+$#', $relative))throw new \RuntimeException('wprism uploads: unsafe artifact path');
    return self::receiptDirectory($root, (string)$m['receipt_id']).'/'.$relative; }
    private static function assertSafePath(string $p): void { if ($p === '' || str_starts_with($p, '/') || str_contains($p, '\\') || preg_match('/[\x00-\x1f\x7f]/', $p) || array_filter(explode('/', $p), fn($x) => $x === '' || $x === '.' || $x === '..'))throw new \RuntimeException("wprism uploads: unsafe upload path '$p'"); }
    private static function assertSafeDirectory(string $p): void { if ($p === '')return;
    self::assertSafePath($p); }
    private static function assertHash(string $v, string $l): void { if (preg_match('/^[0-9a-f]{64}$/', $v) !== 1)throw new \RuntimeException("wprism uploads: $l must be sha256"); }
    private static function assertActor(string $v, string $l): void { if ($v === '' || strlen($v) > 128 || preg_match('/^[A-Za-z0-9._:@+\/-]+$/', $v) !== 1)throw new \RuntimeException("wprism uploads: $l is malformed"); }
    private static function assertIdentifier(string $v, string $l, int $min, int $max): void { if (strlen($v) < $min || strlen($v) > $max || preg_match('/^[A-Za-z0-9._:-]+$/', $v) !== 1)throw new \RuntimeException("wprism uploads: $l is malformed"); }
    /** @param list<string> $expected */ private static function assertExactKeys(array $v, array $expected, string $l): void { $a = array_keys($v);
    sort($a, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($a !== $expected)throw new \RuntimeException("wprism uploads: $l has missing or unknown fields"); }
    private static function timeValue(string $v): int { $t = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $v, new \DateTimeZone('UTC'));
    if (!$t || $t->format('Y-m-d\TH:i:s\Z') !== $v)throw new \RuntimeException('wprism uploads: timestamp must be canonical UTC seconds');
    return $t->getTimestamp(); }
    /** @return array<string,mixed> */ private static function readCanonical(string $p, string $l): array { return AtomicStore::readCanonical($p, $l, 'wprism uploads'); }
    private static function assertAbsoluteRegularFile(string $p, string $l): void { AtomicStore::assertAbsoluteRegularFile($p, $l, 'wprism uploads'); }
    private static function ensureDirectory(string $p, int $m): void { AtomicStore::ensureDirectory($p, $m, 'wprism uploads'); }
    private static function syncDirectory(string $p): void { AtomicStore::syncDirectory($p, 'uploads directory', 'wprism uploads'); }
    private static function publishExact(string $p, string $b, int $m, string $l): void { AtomicStore::publishExact($p, $b, $m, $l, 'wprism uploads', true); }
    private static function receiptDirectory(string $root, string $id): string { self::assertIdentifier($id, 'receipt id', 32, 64);
    return dirname($root).'/rollback/'.$id; }
    /** @template T @param callable():T $callback @return T */ private static function withLock(string $root,callable $callback): mixed { $p = $root.'/upload-bundle.lock';
    return ProtocolLock::withExclusive($p,$callback,'wprism uploads: lock path is unsafe','wprism uploads: could not acquire lock','wprism uploads: could not acquire lock',0600); }
}
