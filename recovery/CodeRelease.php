<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Target-owned immutable code-release preparation and atomic selection.
 *
 * The configured provider owns release storage and the live pointer. This
 * runtime owns the fail-closed protocol around it: complete canonical
 * descriptors, target/generation identity, receipt binding, signed operation
 * authorization, retry-safe pointer transitions, and independent post-switch
 * verification without loading WordPress or site code.
 */
final class CodeRelease {
    private const REQUEST_FORMAT_V1 = 'duo-code-release-request/v1';
    private const REQUEST_FORMAT_V2 = 'duo-code-release-request/v2';
    private const PROVIDER_REQUEST_FORMAT_V1 = 'duo-code-release-provider-request/v1';
    private const PROVIDER_REQUEST_FORMAT_V2 = 'duo-code-release-provider-request/v2';
    private const PROVIDER_RESPONSE_FORMAT = 'duo-code-release-provider-response/v1';
    private const DESCRIPTOR_FORMAT = 'duo-code-release-descriptor/v1';
    private const METADATA_FORMAT = 'duo-code-release-metadata/v1';
    private const OPERATION_FORMAT = 'duo-code-release-operation/v1';
    private const TOMBSTONE_FORMAT = 'duo-code-release-tombstone/v1';

    public static function configured(string $root): bool {
        return array_key_exists('code_release_provider', RecoveryExecutor::configuration($root));
    }

    /** @return array<string,string> */
    public static function statusEvidence(string $root, string $receiptId): array {
        $metadata = self::metadata($root, $receiptId);
        self::verifyMetadataArtifacts($root, $metadata);
        return [
            'desired_code_revision' => (string) $metadata['desired_code_revision'],
            'desired_descriptor_sha256' => (string) $metadata['desired_descriptor_sha256'],
            'desired_pointer_sha256' => (string) $metadata['desired_pointer_sha256'],
            'desired_release_id' => (string) $metadata['desired_release_id'],
            'metadata_sha256' => self::metadataHash($metadata),
            'prior_descriptor_sha256' => (string) $metadata['prior_descriptor_sha256'],
            'prior_pointer_sha256' => (string) $metadata['prior_pointer_sha256'],
            'prior_release_id' => (string) $metadata['prior_release_id'],
            'provider_id' => (string) $metadata['provider_id'],
            'provider_version' => (string) $metadata['provider_version'],
        ];
    }

    /** @return array<string,mixed> */
    public static function probe(string $root): array {
        $config = RecoveryExecutor::configuration($root);
        if (!array_key_exists('code_release_provider', $config)) {
            throw new \RuntimeException('duo code release: no code release provider is configured');
        }
        $status = RollbackControl::status($root);
        $response = self::call($config, self::providerRequest([
            'action' => 'probe',
            'artifact_directory' => null,
            'artifact_hash' => null,
            'claim_epoch' => null,
            'claimant' => null,
            'code_release_metadata_sha256' => null,
            'desired_code_revision' => null,
            'desired_descriptor_path' => null,
            'desired_descriptor_sha256' => null,
            'expected_from_pointer_sha256' => null,
            'generation' => (int) $status['generation'],
            'input_path' => null,
            'input_sha256' => null,
            'owner' => null,
            'prior_descriptor_path' => null,
            'prior_descriptor_sha256' => null,
            'prior_pointer_sha256' => null,
            'receipt_id' => null,
            'release_id' => null,
            'target_id' => (string) $status['target_id'],
        ]));
        self::assertExactKeys($response, [
            'atomic_pointer', 'available', 'build_resolution_off_target', 'format',
            'immutable_releases', 'mutable_resolution', 'plan_bound_code_inventory', 'provider_id',
            'provider_version', 'state', 'target_generation_fenced',
            'target_git_history', 'target_registry_credentials',
            'verified_descriptors',
        ], 'probe response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'ready'
            || ($response['atomic_pointer'] ?? null) !== true
            || ($response['build_resolution_off_target'] ?? null) !== true
            || ($response['immutable_releases'] ?? null) !== true
            || ($response['mutable_resolution'] ?? null) !== false
            || ($response['plan_bound_code_inventory'] ?? null) !== true
            || ($response['target_generation_fenced'] ?? null) !== true
            || ($response['target_git_history'] ?? null) !== false
            || ($response['target_registry_credentials'] ?? null) !== false
            || ($response['verified_descriptors'] ?? null) !== true) {
            throw new \RuntimeException('duo code release: provider did not attest the certified immutable release contract');
        }
        self::assertActor((string) ($response['provider_id'] ?? ''), 'provider id');
        self::assertActor((string) ($response['provider_version'] ?? ''), 'provider version');
        return ['ok' => true] + $response;
    }

    /** @return array<string,mixed> */
    public static function handleRequest(string $root, string $requestPath): array {
        self::assertAbsoluteRegularFile($requestPath, 'signed request');
        $signed = self::readCanonical($requestPath, 'signed request');
        $payload = RollbackControl::verifyEnvelope($root, $signed, 'code release request');
        self::validateRequest($payload);
        return self::withLock($root, function () use ($root, $payload): array {
            $status = RollbackControl::status($root);
            if ((string) $payload['action'] === 'prepare') {
                if (!empty($status['active']) && empty($status['terminal'])) {
                    throw new \RuntimeException('duo code release: prepare refused during a nonterminal generation');
                }
                if ((int) $payload['generation'] !== (int) $status['generation'] + 1
                    || (int) $payload['claim_epoch'] !== 1
                    || !hash_equals((string) $payload['target_id'], (string) $status['target_id'])) {
                    throw new \RuntimeException('duo code release: prepare is not for the exact next target generation');
                }
                $recovery = RecoveryExecutor::decorateStatus($root, $status);
                $reservation = $recovery['exclusion_reservation'] ?? null;
                if (($recovery['exclusion_state'] ?? '') !== 'held' || !is_array($reservation)) {
                    throw new \RuntimeException('duo code release: prepare requires a verified held exclusion reservation');
                }
                foreach (['artifact_hash', 'claim_epoch', 'claimant', 'generation', 'owner', 'receipt_id'] as $key) {
                    if ((string) $payload[$key] !== (string) ($reservation[$key] ?? '')) {
                        throw new \RuntimeException("duo code release: exclusion reservation $key does not match prepare");
                    }
                }
                return self::prepare($root, $payload);
            }
            if (empty($status['active']) || empty($status['terminal'])) {
                throw new \RuntimeException('duo code release: deletion requires signed terminal authority');
            }
            self::assertIdentity($payload, $status, true);
            return self::delete($root, $payload, $status);
        });
    }

    /** Called inside RollbackControl's target lock before receipt publication. */
    public static function assertClaimCodeRelease(string $root, array $receipt, array $event): void {
        $metadata = self::metadata($root, (string) $receipt['receipt_id']);
        self::assertIdentity($metadata, $receipt, false);
        if ((string) $metadata['claimant'] !== (string) $event['claimant']
            || (int) $metadata['claim_epoch'] !== (int) $event['claim_epoch']
            || !hash_equals((string) $metadata['prior_descriptor_sha256'], (string) $receipt['prior_code_descriptor_sha256'])
            || !hash_equals(self::metadataHash($metadata), (string) $receipt['code_release_metadata_sha256'])
            || (string) $metadata['created_at'] !== (string) $receipt['created_at']
            || (string) $metadata['retention_until'] !== (string) $receipt['retention_until']) {
            throw new \RuntimeException('duo code release: prepared release is not bound to the exact claim receipt');
        }
        self::verifyMetadataArtifacts($root, $metadata);
    }

    /** @return array{adapter_version:string,result_sha256:string} */
    public static function execute(
        string $root,
        string $adapter,
        string $inputPath,
        string $inputHash,
        array $status
    ): array {
        if (!in_array($adapter, ['code_select', 'code_restore'], true)) {
            throw new \RuntimeException('duo code release: unsupported code release operation');
        }
        $input = self::readCanonical($inputPath, 'operation input');
        self::assertExactKeys($input, [
            'artifact_hash', 'code_release_metadata_sha256', 'expected_from_pointer_sha256',
            'format', 'generation', 'operation', 'owner', 'receipt_id', 'target_id',
        ], 'operation input');
        $expectedOperation = $adapter === 'code_select' ? 'select_desired' : 'restore_prior';
        if (($input['format'] ?? '') !== self::OPERATION_FORMAT
            || ($input['operation'] ?? '') !== $expectedOperation) {
            throw new \RuntimeException('duo code release: operation input does not name the authorized pointer transition');
        }
        self::assertIdentity($input, $status, false);
        self::assertHash((string) ($input['code_release_metadata_sha256'] ?? ''), 'operation metadata hash');
        self::assertHash((string) ($input['expected_from_pointer_sha256'] ?? ''), 'operation prior pointer hash');
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::assertIdentity($metadata, $status, false);
        self::verifyMetadataArtifacts($root, $metadata);
        if (!hash_equals(self::metadataHash($metadata), (string) $input['code_release_metadata_sha256'])) {
            throw new \RuntimeException('duo code release: operation metadata hash does not match the immutable release receipt');
        }
        if (!hash_equals(self::metadataHash($metadata), (string) ($status['code_release_metadata_sha256'] ?? ''))) {
            throw new \RuntimeException('duo code release: signed receipt does not authorize this release metadata');
        }
        $from = $adapter === 'code_select'
            ? (string) $metadata['prior_pointer_sha256']
            : (string) $metadata['desired_pointer_sha256'];
        if (!hash_equals($from, (string) $input['expected_from_pointer_sha256'])) {
            throw new \RuntimeException('duo code release: operation expected pointer is stale or foreign');
        }
        $role = $adapter === 'code_select' ? 'desired' : 'prior';
        $config = RecoveryExecutor::configuration($root);
        $request = self::providerRequest([
            'action' => $expectedOperation,
            'artifact_directory' => self::receiptDirectory($root, (string) $status['receipt_id']) . '/artifacts',
            'artifact_hash' => (string) $status['artifact_hash'],
            'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'],
            'code_release_metadata_sha256' => self::metadataHash($metadata),
            'desired_code_revision' => (string) $metadata['desired_code_revision'],
            'desired_descriptor_path' => (string) $metadata['desired_descriptor_path'],
            'desired_descriptor_sha256' => (string) $metadata['desired_descriptor_sha256'],
            'expected_from_pointer_sha256' => $from,
            'generation' => (int) $status['generation'],
            'input_path' => $inputPath,
            'input_sha256' => $inputHash,
            'owner' => (string) $status['owner'],
            'prior_descriptor_path' => (string) $metadata['prior_descriptor_path'],
            'prior_descriptor_sha256' => (string) $metadata['prior_descriptor_sha256'],
            'prior_pointer_sha256' => (string) $metadata['prior_pointer_sha256'],
            'receipt_id' => (string) $status['receipt_id'],
            'release_id' => (string) $metadata[$role . '_release_id'],
            'target_id' => (string) $status['target_id'],
        ]);
        $selected = self::call($config, $request);
        self::validateSelection($selected, $request, $metadata, $role, 'selected');
        $verifyRequest = $request;
        $verifyRequest['action'] = 'verify_' . $role;
        $verified = self::call($config, $verifyRequest);
        self::validateSelection($verified, $verifyRequest, $metadata, $role, 'verified');
        if (!hash_equals((string) $selected['pointer_sha256'], (string) $verified['pointer_sha256'])
            || !hash_equals((string) $selected['result_sha256'], (string) $verified['result_sha256'])) {
            throw new \RuntimeException('duo code release: selected pointer changed during independent verification');
        }
        return [
            'adapter_version' => (string) $verified['provider_version'],
            'result_sha256' => (string) $verified['result_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private static function prepare(string $root, array $payload): array {
        $planBound = ($payload['format'] ?? '') === self::REQUEST_FORMAT_V2;
        $dir = self::receiptDirectory($root, (string) $payload['receipt_id']);
        self::ensureDirectory($dir, 0700);
        self::ensureDirectory($dir . '/artifacts', 0700);
        $metadataPath = $dir . '/code-release-metadata.json';
        if (is_file($metadataPath)) {
            $metadata = self::readCanonical($metadataPath, 'code release metadata');
            self::validateMetadata($metadata);
            self::assertIdentity($payload, $metadata, true);
            if (!hash_equals((string) $payload['desired_code_revision'], (string) $metadata['desired_code_revision'])
                || (string) $payload['retention_until'] !== (string) $metadata['retention_until']) {
                throw new \RuntimeException('duo code release: prepare retry changed immutable release inputs');
            }
            self::verifyMetadataArtifacts($root, $metadata);
            if ($planBound) {
                self::assertDesiredInventory(
                    (array) $payload['desired_code_inventory'],
                    self::readDescriptor((string) $metadata['desired_descriptor_path'], 'desired')
                );
            } elseif (!hash_equals(
                (string) $payload['desired_descriptor_sha256'],
                (string) $metadata['desired_descriptor_sha256']
            )) {
                throw new \RuntimeException('duo code release: prepare retry changed immutable release inputs');
            }
            return self::publicMetadata($metadata);
        }
        $desiredPath = $dir . '/artifacts/desired-code-descriptor.json';
        $priorPath = $dir . '/artifacts/prior-code-descriptor.json';
        foreach ([$desiredPath, $priorPath] as $path) {
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('duo code release: descriptor output path is unsafe');
            }
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('duo code release: could not clear interrupted descriptor output');
            }
        }
        self::syncDirectory($dir . '/artifacts');
        $config = RecoveryExecutor::configuration($root);
        $providerFields = [
            'action' => 'prepare',
            'artifact_directory' => $dir . '/artifacts',
            'artifact_hash' => (string) $payload['artifact_hash'],
            'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'],
            'code_release_metadata_sha256' => null,
            'desired_code_revision' => (string) $payload['desired_code_revision'],
            'desired_descriptor_path' => $desiredPath,
            'expected_from_pointer_sha256' => null,
            'generation' => (int) $payload['generation'],
            'input_path' => null,
            'input_sha256' => null,
            'owner' => (string) $payload['owner'],
            'prior_descriptor_path' => $priorPath,
            'prior_descriptor_sha256' => null,
            'prior_pointer_sha256' => null,
            'receipt_id' => (string) $payload['receipt_id'],
            'release_id' => null,
            'target_id' => (string) $payload['target_id'],
        ];
        if ($planBound) {
            $providerFields['desired_code_inventory'] = $payload['desired_code_inventory'];
            $request = self::providerRequest($providerFields, self::PROVIDER_REQUEST_FORMAT_V2);
        } else {
            $providerFields['desired_descriptor_sha256'] = (string) $payload['desired_descriptor_sha256'];
            $request = self::providerRequest($providerFields);
        }
        $response = self::call($config, $request);
        self::assertExactKeys($response, [
            'atomic_pointer', 'available', 'build_resolution_off_target',
            'desired_descriptor_path', 'desired_descriptor_sha256',
            'desired_pointer_sha256', 'desired_release_id', 'format',
            'immutable_releases', 'mutable_resolution', 'plan_bound_code_inventory', 'prior_descriptor_path',
            'prior_descriptor_sha256', 'prior_pointer_sha256', 'prior_release_id',
            'provider_id', 'provider_version', 'state', 'target_generation',
            'target_generation_fenced', 'target_git_history',
            'target_registry_credentials', 'verified_descriptors',
        ], 'prepare response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['state'] ?? '') !== 'prepared'
            || ($response['atomic_pointer'] ?? null) !== true
            || ($response['build_resolution_off_target'] ?? null) !== true
            || ($response['immutable_releases'] ?? null) !== true
            || ($response['mutable_resolution'] ?? null) !== false
            || ($response['plan_bound_code_inventory'] ?? null) !== true
            || ($response['target_generation_fenced'] ?? null) !== true
            || ($response['target_git_history'] ?? null) !== false
            || ($response['target_registry_credentials'] ?? null) !== false
            || ($response['verified_descriptors'] ?? null) !== true
            || (int) ($response['target_generation'] ?? 0) !== (int) $payload['generation']
            || ($response['desired_descriptor_path'] ?? '') !== $desiredPath
            || ($response['prior_descriptor_path'] ?? '') !== $priorPath) {
            throw new \RuntimeException('duo code release: provider preparation did not satisfy the certified release contract');
        }
        foreach (['desired_descriptor_sha256', 'desired_pointer_sha256', 'prior_descriptor_sha256', 'prior_pointer_sha256'] as $key) {
            self::assertHash((string) ($response[$key] ?? ''), "prepare $key");
        }
        if (!$planBound
            && !hash_equals((string) $payload['desired_descriptor_sha256'], (string) $response['desired_descriptor_sha256'])) {
            throw new \RuntimeException('duo code release: provider returned a different desired descriptor');
        }
        foreach (['desired_release_id', 'prior_release_id', 'provider_id', 'provider_version'] as $key) {
            self::assertActor((string) ($response[$key] ?? ''), "prepare $key");
        }
        $desired = self::readDescriptor($desiredPath, 'desired');
        $prior = self::readDescriptor($priorPath, 'prior');
        if ($planBound) {
            self::assertDesiredInventory((array) $payload['desired_code_inventory'], $desired);
        }
        if (!hash_equals((string) hash_file('sha256', $desiredPath), (string) $response['desired_descriptor_sha256'])
            || !hash_equals((string) hash_file('sha256', $priorPath), (string) $response['prior_descriptor_sha256'])
            || !hash_equals((string) $desired['artifact_hash'], (string) $payload['artifact_hash'])
            || !hash_equals((string) $desired['code_revision'], (string) $payload['desired_code_revision'])
            || !hash_equals((string) $desired['release_id'], (string) $response['desired_release_id'])
            || !hash_equals((string) $prior['release_id'], (string) $response['prior_release_id'])
            || (int) $desired['generation'] !== (int) $payload['generation']) {
            throw new \RuntimeException('duo code release: descriptor bytes do not match provider preparation evidence');
        }
        $metadata = [
            'artifact_hash' => (string) $payload['artifact_hash'],
            'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'],
            'created_at' => (string) $payload['timestamp'],
            'desired_code_revision' => (string) $payload['desired_code_revision'],
            'desired_descriptor_path' => $desiredPath,
            'desired_descriptor_sha256' => (string) $response['desired_descriptor_sha256'],
            'desired_pointer_sha256' => (string) $response['desired_pointer_sha256'],
            'desired_release_id' => (string) $response['desired_release_id'],
            'format' => self::METADATA_FORMAT,
            'generation' => (int) $payload['generation'],
            'owner' => (string) $payload['owner'],
            'prior_descriptor_path' => $priorPath,
            'prior_descriptor_sha256' => (string) $response['prior_descriptor_sha256'],
            'prior_pointer_sha256' => (string) $response['prior_pointer_sha256'],
            'prior_release_id' => (string) $response['prior_release_id'],
            'provider_id' => (string) $response['provider_id'],
            'provider_version' => (string) $response['provider_version'],
            'receipt_id' => (string) $payload['receipt_id'],
            'retention_until' => (string) $payload['retention_until'],
            'target_id' => (string) $payload['target_id'],
        ];
        self::validateMetadata($metadata);
        self::atomicWrite($metadataPath, RollbackControl::canonical($metadata) . "\n", 0600, 'code release metadata');
        self::verifyMetadataArtifacts($root, $metadata);
        return self::publicMetadata($metadata);
    }

    /** @return array<string,mixed> */
    private static function delete(string $root, array $payload, array $status): array {
        $tombstonePath = self::receiptDirectory($root, (string) $status['receipt_id']) . '/code-release-tombstone.json';
        if (is_file($tombstonePath)) {
            $existing = self::readCanonical($tombstonePath, 'code release tombstone');
            self::assertExactKeys($existing, ['deleted_at', 'format', 'metadata_sha256', 'prior_release_id', 'provider_id', 'receipt_id'], 'code release tombstone');
            if (($existing['format'] ?? '') !== self::TOMBSTONE_FORMAT
                || !hash_equals((string) ($existing['receipt_id'] ?? ''), (string) $status['receipt_id'])) {
                throw new \RuntimeException('duo code release: deletion tombstone is stale or corrupt');
            }
            return ['deleted' => true, 'ok' => true, 'prior_release_id' => (string) $existing['prior_release_id']];
        }
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::assertIdentity($metadata, $status, false);
        if ((string) $payload['retention_until'] !== (string) $metadata['retention_until']) {
            throw new \RuntimeException('duo code release: deletion retention does not match immutable metadata');
        }
        if (!hash_equals((string) $payload['desired_code_revision'], (string) $metadata['desired_code_revision'])
            || !hash_equals((string) $payload['desired_descriptor_sha256'], (string) $metadata['desired_descriptor_sha256'])) {
            throw new \RuntimeException('duo code release: deletion release identity does not match immutable metadata');
        }
        if (self::timeValue((string) $payload['timestamp']) < self::timeValue((string) $metadata['retention_until'])) {
            throw new \RuntimeException('duo code release: rollback release retention has not elapsed');
        }
        $config = RecoveryExecutor::configuration($root);
        $request = self::providerRequest([
            'action' => 'delete_prior', 'artifact_directory' => self::receiptDirectory($root, (string) $status['receipt_id']) . '/artifacts',
            'artifact_hash' => (string) $status['artifact_hash'], 'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'], 'code_release_metadata_sha256' => self::metadataHash($metadata),
            'desired_code_revision' => (string) $metadata['desired_code_revision'], 'desired_descriptor_path' => (string) $metadata['desired_descriptor_path'],
            'desired_descriptor_sha256' => (string) $metadata['desired_descriptor_sha256'], 'expected_from_pointer_sha256' => null,
            'generation' => (int) $status['generation'], 'input_path' => null, 'input_sha256' => null,
            'owner' => (string) $status['owner'], 'prior_descriptor_path' => (string) $metadata['prior_descriptor_path'],
            'prior_descriptor_sha256' => (string) $metadata['prior_descriptor_sha256'], 'prior_pointer_sha256' => (string) $metadata['prior_pointer_sha256'],
            'receipt_id' => (string) $status['receipt_id'], 'release_id' => (string) $metadata['prior_release_id'],
            'target_id' => (string) $status['target_id'],
        ]);
        $response = self::call($config, $request);
        self::assertExactKeys($response, ['action', 'available', 'format', 'prior_release_absent', 'provider_id', 'provider_version', 'state'], 'delete response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['action'] ?? '') !== 'delete_prior'
            || ($response['state'] ?? '') !== 'deleted'
            || ($response['prior_release_absent'] ?? null) !== true
            || !hash_equals((string) ($response['provider_id'] ?? ''), (string) $metadata['provider_id'])
            || !hash_equals((string) ($response['provider_version'] ?? ''), (string) $metadata['provider_version'])) {
            throw new \RuntimeException('duo code release: provider did not prove retained prior release deletion');
        }
        $tombstone = ['deleted_at' => (string) $payload['timestamp'], 'format' => self::TOMBSTONE_FORMAT,
            'metadata_sha256' => self::metadataHash($metadata), 'prior_release_id' => (string) $metadata['prior_release_id'],
            'provider_id' => (string) $metadata['provider_id'], 'receipt_id' => (string) $status['receipt_id']];
        self::atomicWrite($tombstonePath, RollbackControl::canonical($tombstone) . "\n", 0600, 'code release tombstone');
        return ['deleted' => true, 'ok' => true, 'prior_release_id' => (string) $metadata['prior_release_id']];
    }

    /** @param array<string,mixed> $response @param array<string,mixed> $request @param array<string,mixed> $metadata */
    private static function validateSelection(array $response, array $request, array $metadata, string $role, string $state): void {
        self::assertExactKeys($response, [
            'action', 'atomic_pointer', 'available', 'descriptor_sha256', 'format',
            'generation', 'no_unrecorded_owned_paths', 'pointer_sha256',
            'provider_id', 'provider_version', 'release_id', 'result_sha256',
            'state', 'symlinks_absent', 'target_id', 'verified_file_inventory',
        ], "$role $state response");
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['action'] ?? '') !== ($request['action'] ?? '')
            || ($response['state'] ?? '') !== $state
            || ($response['atomic_pointer'] ?? null) !== true
            || ($response['verified_file_inventory'] ?? null) !== true
            || ($response['no_unrecorded_owned_paths'] ?? null) !== true
            || ($response['symlinks_absent'] ?? null) !== true
            || (int) ($response['generation'] ?? 0) !== (int) $metadata['generation']
            || !hash_equals((string) ($response['target_id'] ?? ''), (string) $metadata['target_id'])
            || !hash_equals((string) ($response['provider_id'] ?? ''), (string) $metadata['provider_id'])
            || !hash_equals((string) ($response['provider_version'] ?? ''), (string) $metadata['provider_version'])
            || !hash_equals((string) ($response['release_id'] ?? ''), (string) $metadata[$role . '_release_id'])
            || !hash_equals((string) ($response['descriptor_sha256'] ?? ''), (string) $metadata[$role . '_descriptor_sha256'])
            || !hash_equals((string) ($response['pointer_sha256'] ?? ''), (string) $metadata[$role . '_pointer_sha256'])) {
            throw new \RuntimeException("duo code release: provider did not prove the exact $role release after pointer transition");
        }
        self::assertHash((string) ($response['result_sha256'] ?? ''), "$role result hash");
        self::assertActor((string) ($response['provider_version'] ?? ''), 'provider version');
    }

    /** @return array<string,mixed> */
    private static function readDescriptor(string $path, string $role): array {
        self::assertAbsoluteRegularFile($path, "$role descriptor");
        $descriptor = self::readCanonical($path, "$role descriptor");
        self::assertExactKeys($descriptor, ['artifact_hash', 'code_revision', 'files', 'format', 'generation', 'owned_roots', 'release_id', 'role'], "$role descriptor");
        if (($descriptor['format'] ?? '') !== self::DESCRIPTOR_FORMAT || ($descriptor['role'] ?? '') !== $role) {
            throw new \RuntimeException("duo code release: malformed $role descriptor identity");
        }
        self::assertHash((string) ($descriptor['artifact_hash'] ?? ''), "$role artifact hash");
        self::assertHash((string) ($descriptor['code_revision'] ?? ''), "$role code revision");
        self::assertActor((string) ($descriptor['release_id'] ?? ''), "$role release id");
        if (!is_int($descriptor['generation'] ?? null) || (int) $descriptor['generation'] < 0) {
            throw new \RuntimeException("duo code release: $role generation is invalid");
        }
        if (!is_array($descriptor['owned_roots'] ?? null) || !array_is_list($descriptor['owned_roots'])) {
            throw new \RuntimeException("duo code release: $role owned roots must be a list");
        }
        $roots = [];
        foreach ($descriptor['owned_roots'] as $root) {
            self::assertSafeRelativePath($root, "$role owned root");
            if (isset($roots[$root])) throw new \RuntimeException("duo code release: duplicate $role owned root");
            $roots[$root] = true;
        }
        if (!is_array($descriptor['files'] ?? null) || !array_is_list($descriptor['files'])) {
            throw new \RuntimeException("duo code release: $role files must be a list");
        }
        $paths = [];
        foreach ($descriptor['files'] as $index => $file) {
            if (!is_array($file) || array_keys($file) !== ['path', 'sha256', 'type']
                || !in_array($file['type'] ?? null, ['directory', 'file'], true)) {
                throw new \RuntimeException("duo code release: $role file[$index] is malformed or a symlink");
            }
            self::assertSafeRelativePath($file['path'] ?? null, "$role file path");
            self::assertHash((string) ($file['sha256'] ?? ''), "$role file hash");
            $path = (string) $file['path'];
            if (isset($paths[$path])) throw new \RuntimeException("duo code release: duplicate $role file path");
            $paths[$path] = true;
            $owned = false;
            foreach (array_keys($roots) as $root) if ($path === $root || str_starts_with($path, $root . '/')) { $owned = true; break; }
            if (!$owned) throw new \RuntimeException("duo code release: $role file escapes declared owned roots");
        }
        return $descriptor;
    }

    /**
     * Prove that a provider-owned, generation-specific release descriptor is
     * exactly the compiled code inventory. Release ids and directory rows are
     * provider-owned; every managed root and regular-file hash is not.
     *
     * @param array<string,mixed> $inventory
     * @param array<string,mixed> $desired
     */
    private static function assertDesiredInventory(array $inventory, array $desired): void {
        [$roots, $files] = self::validateCompiledCodeInventory($inventory);
        if (!hash_equals((string) $inventory['code_revision'], (string) $desired['code_revision'])) {
            throw new \RuntimeException('duo code release: desired release revision disagrees with compiled plan');
        }
        $expectedRoots = array_map(static fn(string $root): string => 'wp-content/' . $root, $roots);
        if ($desired['owned_roots'] !== $expectedRoots) {
            throw new \RuntimeException('duo code release: desired release roots disagree with compiled plan');
        }

        $expected = [];
        foreach ($roots as $root) {
            $hasExactFile = array_key_exists($root, $files);
            $hasDescendant = false;
            foreach (array_keys($files) as $path) {
                if (!str_starts_with($path, $root . '/')) continue;
                $hasDescendant = true;
                $directory = dirname($path);
                while ($directory === $root || str_starts_with($directory, $root . '/')) {
                    $releasePath = 'wp-content/' . $directory;
                    $expected[$releasePath] = [
                        'path' => $releasePath,
                        'sha256' => hash('sha256', ''),
                        'type' => 'directory',
                    ];
                    if ($directory === $root) break;
                    $directory = dirname($directory);
                }
            }
            if (!$hasExactFile && !$hasDescendant) {
                $releasePath = 'wp-content/' . $root;
                $expected[$releasePath] = [
                    'path' => $releasePath,
                    'sha256' => hash('sha256', ''),
                    'type' => 'directory',
                ];
            }
        }
        foreach ($files as $path => $sha256) {
            $releasePath = 'wp-content/' . $path;
            if (isset($expected[$releasePath])) {
                throw new \RuntimeException('duo code release: compiled plan treats one path as both file and directory');
            }
            $expected[$releasePath] = [
                'path' => $releasePath,
                'sha256' => $sha256,
                'type' => 'file',
            ];
        }
        ksort($expected, SORT_STRING);
        if ($desired['files'] !== array_values($expected)) {
            throw new \RuntimeException('duo code release: desired release paths or hashes disagree with compiled plan');
        }
    }

    /**
     * Validate the independently signed `duo-code/v1` inventory without
     * loading WordPress or agent code into the recovery process.
     *
     * @param array<string,mixed> $inventory
     * @return array{0:list<string>,1:array<string,string>}
     */
    private static function validateCompiledCodeInventory(array $inventory): array {
        $legacy = ['code_revision', 'files', 'format', 'layout', 'owned_roots', 'plugin_main_files', 'source', 'theme_slugs'];
        $current = [...$legacy, 'theme_templates'];
        $keys = array_keys($inventory);
        sort($keys, SORT_STRING);
        sort($legacy, SORT_STRING);
        sort($current, SORT_STRING);
        if (($keys !== $legacy && $keys !== $current)
            || ($inventory['format'] ?? '') !== 'duo-code/v1'
            || ($inventory['layout'] ?? '') !== 'wp-content'
            || ($inventory['source'] ?? '') !== 'code/wp-content'
            || !is_array($inventory['owned_roots'] ?? null)
            || !array_is_list($inventory['owned_roots'])
            || !is_array($inventory['files'] ?? null)
            || !array_is_list($inventory['files'])
            || !is_array($inventory['plugin_main_files'] ?? null)
            || !array_is_list($inventory['plugin_main_files'])
            || !is_array($inventory['theme_slugs'] ?? null)
            || !array_is_list($inventory['theme_slugs'])) {
            throw new \RuntimeException('duo code release: compiled code inventory is malformed');
        }
        self::assertHash((string) ($inventory['code_revision'] ?? ''), 'compiled code revision');

        $roots = [];
        foreach ($inventory['owned_roots'] as $root) {
            self::assertSafeRelativePath($root, 'compiled owned root');
            $parts = explode('/', $root);
            if (count($parts) !== 2
                || !in_array($parts[0], ['mu-plugins', 'plugins', 'themes'], true)
                || isset($roots[$root])) {
                throw new \RuntimeException('duo code release: compiled owned roots are malformed');
            }
            $roots[$root] = true;
        }
        $rootList = array_keys($roots);
        $sortedRoots = $rootList;
        sort($sortedRoots, SORT_STRING);
        if ($rootList !== $sortedRoots) {
            throw new \RuntimeException('duo code release: compiled owned roots are not sorted');
        }

        $files = [];
        foreach ($inventory['files'] as $row) {
            if (!is_array($row) || array_keys($row) !== ['path', 'sha256']) {
                throw new \RuntimeException('duo code release: compiled file inventory is malformed');
            }
            $path = $row['path'] ?? null;
            self::assertSafeRelativePath($path, 'compiled file path');
            self::assertHash((string) ($row['sha256'] ?? ''), 'compiled file hash');
            if (isset($files[$path])) {
                throw new \RuntimeException('duo code release: compiled file inventory contains a duplicate path');
            }
            $owned = false;
            foreach ($rootList as $root) {
                if ($path === $root || str_starts_with($path, $root . '/')) {
                    $owned = true;
                    break;
                }
            }
            if (!$owned) {
                throw new \RuntimeException('duo code release: compiled file escapes its owned roots');
            }
            $files[$path] = (string) $row['sha256'];
        }
        $filePaths = array_keys($files);
        $sortedPaths = $filePaths;
        sort($sortedPaths, SORT_STRING);
        if ($filePaths !== $sortedPaths) {
            throw new \RuntimeException('duo code release: compiled files are not sorted');
        }

        $withoutRevision = $inventory;
        unset($withoutRevision['code_revision']);
        $normalized = self::normalizeCompiledCode($withoutRevision);
        $bytes = json_encode(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
        if (!hash_equals(hash('sha256', $bytes), (string) $inventory['code_revision'])) {
            throw new \RuntimeException('duo code release: compiled code revision does not verify');
        }
        return [$rootList, $files];
    }

    private static function normalizeCompiledCode(mixed $value): mixed {
        if (!is_array($value)) return $value;
        $list = array_is_list($value);
        foreach ($value as $key => $item) $value[$key] = self::normalizeCompiledCode($item);
        if (!$list) ksort($value, SORT_STRING);
        return $value;
    }

    private static function assertSafeRelativePath(mixed $path, string $label): void {
        if (!is_string($path) || $path === '' || $path[0] === '/' || str_contains($path, "\0")
            || str_contains($path, '\\') || preg_match('#(^|/)\.\.?(?:/|$)#', $path) === 1
            || preg_match('#//+#', $path) === 1) {
            throw new \RuntimeException("duo code release: $label is unsafe");
        }
    }

    /** @param array<string,mixed> $metadata */
    private static function verifyMetadataArtifacts(string $root, array $metadata): void {
        self::validateMetadata($metadata);
        foreach (['desired', 'prior'] as $role) {
            $path = (string) $metadata[$role . '_descriptor_path'];
            $descriptor = self::readDescriptor($path, $role);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals($hash, (string) $metadata[$role . '_descriptor_sha256'])
                || !hash_equals((string) $descriptor['release_id'], (string) $metadata[$role . '_release_id'])) {
                throw new \RuntimeException("duo code release: immutable $role descriptor changed");
            }
        }
    }

    /** @return array<string,mixed> */
    private static function metadata(string $root, string $receiptId): array {
        $metadata = self::readCanonical(self::receiptDirectory($root, $receiptId) . '/code-release-metadata.json', 'code release metadata');
        self::validateMetadata($metadata);
        return $metadata;
    }

    /** @param array<string,mixed> $metadata */
    private static function validateMetadata(array $metadata): void {
        self::assertExactKeys($metadata, [
            'artifact_hash', 'claim_epoch', 'claimant', 'created_at', 'desired_code_revision',
            'desired_descriptor_path', 'desired_descriptor_sha256', 'desired_pointer_sha256',
            'desired_release_id', 'format', 'generation', 'owner', 'prior_descriptor_path',
            'prior_descriptor_sha256', 'prior_pointer_sha256', 'prior_release_id', 'provider_id',
            'provider_version', 'receipt_id', 'retention_until', 'target_id',
        ], 'code release metadata');
        if (($metadata['format'] ?? '') !== self::METADATA_FORMAT) throw new \RuntimeException('duo code release: unsupported metadata format');
        self::assertIdentityFields($metadata);
        foreach (['desired_code_revision', 'desired_descriptor_sha256', 'desired_pointer_sha256', 'prior_descriptor_sha256', 'prior_pointer_sha256'] as $key) self::assertHash((string) ($metadata[$key] ?? ''), "metadata $key");
        foreach (['claimant', 'desired_release_id', 'prior_release_id', 'provider_id', 'provider_version'] as $key) self::assertActor((string) ($metadata[$key] ?? ''), "metadata $key");
        self::assertAbsoluteRegularFile((string) ($metadata['desired_descriptor_path'] ?? ''), 'desired descriptor');
        self::assertAbsoluteRegularFile((string) ($metadata['prior_descriptor_path'] ?? ''), 'prior descriptor');
        if (self::timeValue((string) $metadata['retention_until']) <= self::timeValue((string) $metadata['created_at'])) throw new \RuntimeException('duo code release: retention must end after preparation');
    }

    /** @return array<string,mixed> */
    private static function publicMetadata(array $metadata): array {
        return ['code_release_metadata_sha256' => self::metadataHash($metadata), 'desired_code_revision' => (string) $metadata['desired_code_revision'],
            'desired_descriptor_sha256' => (string) $metadata['desired_descriptor_sha256'], 'desired_pointer_sha256' => (string) $metadata['desired_pointer_sha256'],
            'desired_release_id' => (string) $metadata['desired_release_id'], 'ok' => true,
            'prior_code_descriptor_sha256' => (string) $metadata['prior_descriptor_sha256'], 'prior_pointer_sha256' => (string) $metadata['prior_pointer_sha256'],
            'prior_release_id' => (string) $metadata['prior_release_id'], 'provider_id' => (string) $metadata['provider_id'], 'provider_version' => (string) $metadata['provider_version']];
    }

    private static function metadataHash(array $metadata): string { return hash('sha256', RollbackControl::canonical($metadata)); }

    /** @param array<string,mixed> $payload */
    private static function validateRequest(array $payload): void {
        $format = $payload['format'] ?? '';
        if ($format === self::REQUEST_FORMAT_V2) {
            self::assertExactKeys($payload, ['action', 'artifact_hash', 'claim_epoch', 'claimant', 'desired_code_inventory', 'desired_code_revision', 'format', 'generation', 'owner', 'receipt_id', 'retention_until', 'target_id', 'timestamp'], 'request payload');
            if (($payload['action'] ?? null) !== 'prepare' || !is_array($payload['desired_code_inventory'] ?? null)) {
                throw new \RuntimeException('duo code release: v2 is only a plan-bound prepare request');
            }
            self::validateCompiledCodeInventory($payload['desired_code_inventory']);
            if (!hash_equals(
                (string) ($payload['desired_code_revision'] ?? ''),
                (string) ($payload['desired_code_inventory']['code_revision'] ?? '')
            )) {
                throw new \RuntimeException('duo code release: request revision disagrees with compiled code inventory');
            }
        } else {
            self::assertExactKeys($payload, ['action', 'artifact_hash', 'claim_epoch', 'claimant', 'desired_code_revision', 'desired_descriptor_sha256', 'format', 'generation', 'owner', 'receipt_id', 'retention_until', 'target_id', 'timestamp'], 'request payload');
            if ($format !== self::REQUEST_FORMAT_V1 || !in_array($payload['action'] ?? null, ['prepare', 'delete'], true)) {
                throw new \RuntimeException('duo code release: unsupported request');
            }
            self::assertHash((string) ($payload['desired_descriptor_sha256'] ?? ''), 'request desired_descriptor_sha256');
        }
        self::assertIdentityFields($payload);
        self::assertHash((string) ($payload['desired_code_revision'] ?? ''), 'request desired_code_revision');
        self::assertActor((string) ($payload['claimant'] ?? ''), 'request claimant');
        self::timeValue((string) ($payload['timestamp'] ?? '')); self::timeValue((string) ($payload['retention_until'] ?? ''));
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function assertIdentity(array $left, array $right, bool $claim): void {
        foreach (['artifact_hash', 'generation', 'owner', 'receipt_id', 'target_id'] as $key) if ((string) ($left[$key] ?? '') !== (string) ($right[$key] ?? '')) throw new \RuntimeException("duo code release: $key identity mismatch");
        if ($claim && ((string) ($left['claimant'] ?? '') !== (string) ($right['claimant'] ?? '') || (int) ($left['claim_epoch'] ?? 0) !== (int) ($right['claim_epoch'] ?? 0))) throw new \RuntimeException('duo code release: claimant identity mismatch');
    }

    /** @param array<string,mixed> $value */
    private static function assertIdentityFields(array $value): void {
        self::assertHash((string) ($value['artifact_hash'] ?? ''), 'artifact hash');
        self::assertIdentifier((string) ($value['receipt_id'] ?? ''), 'receipt id', 32, 64);
        self::assertIdentifier((string) ($value['target_id'] ?? ''), 'target id', 32, 32);
        self::assertActor((string) ($value['owner'] ?? ''), 'owner');
        if (!is_int($value['generation'] ?? null) || (int) $value['generation'] < 1 || !is_int($value['claim_epoch'] ?? null) || (int) $value['claim_epoch'] < 1) throw new \RuntimeException('duo code release: generation/claim epoch must be positive integers');
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function providerRequest(
        array $request,
        string $format = self::PROVIDER_REQUEST_FORMAT_V1
    ): array {
        return ['format' => $format] + $request;
    }

    /** @return array<string,mixed> */
    private static function call(array $config, array $request): array {
        $command = $config['code_release_provider'] ?? null;
        if (!is_array($command) || $command === []) throw new \RuntimeException('duo code release: provider is not configured');
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new \RuntimeException('duo code release: could not start provider');
        fwrite($pipes[0], RollbackControl::canonical($request) . "\n"); fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $observed = null; $deadline = microtime(true) + (int) $config['timeout_seconds'];
        do { $stdout .= (string) stream_get_contents($pipes[1]); $stderr .= (string) stream_get_contents($pipes[2]); $state = proc_get_status($process); if (!$state['running']) { $observed = (int) $state['exitcode']; break; } if (microtime(true) >= $deadline) { proc_terminate($process, 9); throw new \RuntimeException('duo code release: provider timed out; exclusion remains held'); } usleep(10000); } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]); $stderr .= (string) stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process); $exit = $observed ?? $closed;
        if ($exit !== 0) { $detail = trim($stderr !== '' ? $stderr : $stdout); throw new \RuntimeException('duo code release: provider failed' . ($detail !== '' ? ': ' . substr($detail, 0, 1000) : '')); }
        try { $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new \RuntimeException('duo code release: provider returned malformed JSON'); }
        if (!is_array($decoded) || RollbackControl::canonical($decoded) . "\n" !== $stdout) throw new \RuntimeException('duo code release: provider returned non-canonical evidence');
        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function readCanonical(string $path, string $label): array {
        self::assertAbsoluteRegularFile($path, $label); $bytes = file_get_contents($path);
        try { $decoded = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null; } catch (\Throwable $e) { throw new \RuntimeException("duo code release: $label is malformed JSON"); }
        if (!is_array($decoded) || RollbackControl::canonical($decoded) . "\n" !== $bytes) throw new \RuntimeException("duo code release: $label is not canonical JSON");
        return $decoded;
    }

    private static function assertAbsoluteRegularFile(string $path, string $label): void { if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) throw new \RuntimeException("duo code release: $label must be an absolute regular file"); }
    private static function assertHash(string $value, string $label): void { if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) throw new \RuntimeException("duo code release: $label must be a sha256 hex digest"); }
    private static function assertActor(string $value, string $label): void { if (preg_match('/^[A-Za-z0-9._:@+-]{1,128}$/', $value) !== 1) throw new \RuntimeException("duo code release: $label is invalid"); }
    private static function assertIdentifier(string $value, string $label, int $min, int $max): void { $length = strlen($value); if ($length < $min || $length > $max || preg_match('/^[a-f0-9]+$/', $value) !== 1) throw new \RuntimeException("duo code release: $label is invalid"); }
    private static function timeValue(string $value): int { $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC')); if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) throw new \RuntimeException('duo code release: timestamp must be canonical UTC seconds'); return $time->getTimestamp(); }
    private static function assertExactKeys(array $value, array $expected, string $label): void { $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING); if ($actual !== $expected) throw new \RuntimeException("duo code release: $label has missing or unknown fields"); }

    private static function receiptDirectory(string $root, string $receiptId): string { self::assertIdentifier($receiptId, 'receipt id', 32, 64); return dirname($root) . '/rollback/' . $receiptId; }
    private static function ensureDirectory(string $path, int $mode): void { if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new \RuntimeException('duo code release: directory path is unsafe'); if (!is_dir($path) && !mkdir($path, $mode, true)) throw new \RuntimeException('duo code release: could not create directory'); chmod($path, $mode); }
    private static function syncDirectory(string $path): void { $fh = @fopen($path, 'r'); if (is_resource($fh)) { @fsync($fh); fclose($fh); } }
    private static function atomicWrite(string $path, string $bytes, int $mode, string $label): void { if (is_link($path) || (file_exists($path) && !is_file($path))) throw new \RuntimeException("duo code release: $label path is unsafe"); $tmp = $path . '.tmp-' . bin2hex(random_bytes(8)); $fh = @fopen($tmp, 'x+b'); if (!is_resource($fh)) throw new \RuntimeException("duo code release: could not create $label"); try { chmod($tmp, $mode); if (fwrite($fh, $bytes) !== strlen($bytes) || !fflush($fh) || !fsync($fh)) throw new \RuntimeException("duo code release: could not persist $label"); fclose($fh); $fh = null; if (!rename($tmp, $path)) throw new \RuntimeException("duo code release: could not publish $label"); self::syncDirectory(dirname($path)); } finally { if (is_resource($fh)) fclose($fh); @unlink($tmp); } }
    /** @template T @param callable():T $callback @return T */
    private static function withLock(string $root, callable $callback): mixed { $path = $root . '/code-release.lock'; if (is_link($path) || (file_exists($path) && !is_file($path))) throw new \RuntimeException('duo code release: lock path is unsafe'); $fh = @fopen($path, 'c+b'); if (!is_resource($fh) || !flock($fh, LOCK_EX)) throw new \RuntimeException('duo code release: could not acquire lock'); try { return $callback(); } finally { flock($fh, LOCK_UN); fclose($fh); } }
}
