<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Target-owned maintenance exclusion and WordPress-independent recovery.
 *
 * Every provider/adapter is an explicitly configured argv vector. The
 * runtime never invokes a shell and never loads wp-config.php, plugins,
 * themes, or user MU plugins. Provider tokens stay in the mode-0600 control
 * root; only their sha256 digest is admitted to the immutable receipt.
 */
final class RecoveryExecutor {
    private const CONFIG_FORMAT = 'duo-recovery-config/v1';
    private const EXCLUSION_FORMAT = 'duo-exclusion-record/v1';
    private const REQUEST_FORMAT = 'duo-exclusion-request/v1';
    private const PROVIDER_REQUEST_FORMAT = 'duo-exclusion-provider-request/v1';
    private const PROVIDER_RESPONSE_FORMAT = 'duo-exclusion-provider-response/v1';
    private const ADAPTER_REQUEST_FORMAT = 'duo-recovery-adapter-request/v1';
    private const ADAPTER_RESPONSE_FORMAT = 'duo-recovery-adapter-response/v1';
    /** @var list<string> */
    private const SCOPES = ['background_jobs', 'filesystem_writers', 'package_updates', 'public_traffic'];
    /** @var list<string> */
    private const ADAPTERS = ['code_restore', 'database_restore', 'prior_verify', 'storage_restore'];

    public static function configured(string $root): bool {
        $path = self::configPath($root);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo recovery: configuration path is unsafe');
        }
        return is_file($path);
    }

    /** @return array<string,mixed> */
    public static function configuration(string $root): array {
        return self::config($root);
    }

    /** @return array<string,mixed> */
    public static function configureFromFile(string $root, string $path): array {
        self::assertAbsoluteRegularFile($path, 'configuration');
        $config = self::readCanonical($path, 'configuration');
        self::validateConfig($config);
        $status = RollbackControl::status($root);
        if (!empty($status['active']) && empty($status['terminal'])) {
            throw new \RuntimeException('duo recovery: cannot replace configuration during a nonterminal generation');
        }
        $record = self::readExclusion($root, false);
        if ($record !== null && ($record['state'] ?? '') === 'held') {
            throw new \RuntimeException('duo recovery: cannot replace configuration while exclusion is held');
        }
        self::atomicWrite(self::configPath($root), RollbackControl::canonical($config) . "\n", 0600, 'configuration');
        return ['configured' => true, 'format' => self::CONFIG_FORMAT, 'ok' => true];
    }

    /** @return array<string,mixed> */
    public static function probe(string $root): array {
        $config = self::config($root);
        $status = RollbackControl::status($root);
        $provider = self::callProvider($config, [
            'action' => 'probe',
            'artifact_hash' => null,
            'claim_epoch' => null,
            'claimant' => null,
            'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => (int) $status['generation'],
            'owner' => null,
            'receipt_id' => null,
            'scopes' => self::scopeMap(),
            'target_id' => (string) $status['target_id'],
            'token' => null,
        ], 'ready');
        $adapters = [];
        foreach (self::ADAPTERS as $adapter) {
            $adapters[$adapter] = self::callAdapter($config, $adapter, [
                'action' => 'probe',
                'adapter' => $adapter,
                'attempt' => null,
                'claim_epoch' => null,
                'claimant' => null,
                'format' => self::ADAPTER_REQUEST_FORMAT,
                'input_path' => null,
                'input_sha256' => null,
                'operation_id' => null,
                'receipt_id' => null,
                'target_id' => (string) $status['target_id'],
            ], 'ready');
        }
        $result = [
            'adapters' => $adapters,
            'format' => self::CONFIG_FORMAT,
            'ok' => true,
            'provider_id' => (string) $provider['provider_id'],
            'provider_version' => (string) $provider['provider_version'],
            'scopes' => self::scopeMap(),
        ];
        if (array_key_exists('checkpoint_provider', $config)) {
            $result['checkpoint'] = CheckpointBundle::probe($root);
        }
        if (array_key_exists('code_release_provider', $config)) {
            $result['code_release'] = CodeRelease::probe($root);
        }
        if (array_key_exists('upload_provider', $config)) {
            $result['uploads'] = UploadBundle::probe($root);
        }
        if (array_key_exists('effect_provider', $config)) {
            $result['effects'] = EffectBundle::probe($root);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public static function decorateStatus(string $root, array $status): array {
        if (!self::configured($root)) {
            return $status + ['recovery_configured' => false, 'recovery_ready' => false];
        }
        $record = self::readExclusion($root, false);
        if ($record === null) {
            self::probe($root);
            $ready = $status + [
                'exclusion_state' => 'none',
                'recovery_configured' => true,
                'recovery_ready' => true,
            ];
            if (array_key_exists('code_release_provider', self::config($root))) {
                $ready['automatic_code_rollback'] = true;
            } else {
                $ready['automatic_code_rollback'] = false;
                $ready['code_recovery'] = 'manual';
            }
            if (!array_key_exists('upload_provider', self::config($root))) {
                $ready['upload_recovery'] = 'manual';
            }
            if (!array_key_exists('effect_provider', self::config($root))) {
                $ready['effect_recovery'] = 'manual';
            }
            return $ready;
        }
        self::validateRecord($record);
        if (($record['state'] ?? '') === 'held') {
            if (!empty($status['active'])
                && (int) $record['generation'] === (int) $status['generation']) {
                self::assertRecordIdentity($record, $status, true);
                if (!hash_equals((string) $status['exclusion_token_sha256'], (string) $record['token_sha256'])) {
                    throw new \RuntimeException('duo recovery: active receipt exclusion hash does not match held token');
                }
            } else {
                if ((!empty($status['active']) && empty($status['terminal']))
                    || (int) $record['generation'] !== (int) $status['generation'] + 1
                    || !hash_equals((string) $record['target_id'], (string) $status['target_id'])) {
                    throw new \RuntimeException('duo recovery: held reservation is not for the exact next generation');
                }
            }
            self::verifyHeld($root, $record, 'verify');
        } elseif (empty($status['active']) || empty($status['terminal'])) {
            throw new \RuntimeException('duo recovery: released exclusion lacks a terminal signed receipt');
        } else {
            self::assertRecordIdentity($record, $status, false);
        }
        $decorated = $status + [
            'exclusion_reservation' => [
                'artifact_hash' => (string) $record['artifact_hash'],
                'claim_epoch' => (int) $record['claim_epoch'],
                'claimant' => (string) $record['claimant'],
                'generation' => (int) $record['generation'],
                'owner' => (string) $record['owner'],
                'receipt_id' => (string) $record['receipt_id'],
                'reserved_at' => (string) $record['updated_at'],
                'token_sha256' => (string) $record['token_sha256'],
            ],
            'exclusion_state' => (string) $record['state'],
            'recovery_configured' => true,
            'recovery_ready' => true,
        ];
        if (array_key_exists('code_release_provider', self::config($root))) {
            $hasBoundRelease = empty($status['active'])
                || is_string($status['code_release_metadata_sha256'] ?? null);
            $decorated['automatic_code_rollback'] = $hasBoundRelease;
            if (!empty($status['active']) && $hasBoundRelease) {
                $decorated['code_release'] = CodeRelease::statusEvidence(
                    $root,
                    (string) $status['receipt_id']
                );
                if (!hash_equals(
                    (string) $status['code_release_metadata_sha256'],
                    (string) $decorated['code_release']['metadata_sha256']
                )) {
                    throw new \RuntimeException('duo recovery: active receipt code release metadata hash does not match target evidence');
                }
            } elseif (!empty($status['active'])) {
                $decorated['code_recovery'] = 'manual';
            }
        } else {
            $decorated['automatic_code_rollback'] = false;
            $decorated['code_recovery'] = 'manual';
        }
        if (array_key_exists('upload_provider', self::config($root))) {
            $hasBoundUploads = empty($status['active'])
                || is_string($status['uploads_inventory_sha256'] ?? null);
            if (!empty($status['active']) && $hasBoundUploads) {
                $decorated['uploads'] = UploadBundle::statusEvidence($root, (string) $status['receipt_id']);
                if (!hash_equals((string) $status['uploads_inventory_sha256'], (string) $decorated['uploads']['metadata_sha256'])) {
                    throw new \RuntimeException('duo recovery: active receipt upload metadata hash does not match target evidence');
                }
            }
        } else {
            $decorated['upload_recovery'] = 'manual';
        }
        if (array_key_exists('effect_provider', self::config($root))) {
            $hasBoundEffects = empty($status['active'])
                || is_string($status['lifecycle_receipts_sha256'] ?? null);
            if (!empty($status['active']) && $hasBoundEffects) {
                $decorated['effects'] = EffectBundle::statusEvidence($root, (string) $status['receipt_id']);
                if (!hash_equals(
                    (string) $status['lifecycle_receipts_sha256'],
                    (string) $decorated['effects']['metadata_sha256']
                )) {
                    throw new \RuntimeException('duo recovery: active receipt effect metadata hash does not match target evidence');
                }
            } elseif (!empty($status['active'])) {
                $decorated['effect_recovery'] = 'manual';
            }
        } else {
            $decorated['effect_recovery'] = 'manual';
        }
        return $decorated;
    }

    /** @return array<string,mixed> */
    public static function handleExclusionRequest(string $root, string $requestPath): array {
        self::assertAbsoluteRegularFile($requestPath, 'signed exclusion request');
        $signed = self::readCanonical($requestPath, 'signed exclusion request');
        $payload = RollbackControl::verifyEnvelope($root, $signed, 'exclusion request');
        self::assertExactKeys($payload, [
            'action', 'artifact_hash', 'claim_epoch', 'claimant', 'format', 'generation',
            'owner', 'receipt_id', 'target_id', 'timestamp',
        ], 'exclusion request payload');
        if (($payload['format'] ?? '') !== self::REQUEST_FORMAT) {
            throw new \RuntimeException('duo recovery: unsupported exclusion request format');
        }
        $action = (string) ($payload['action'] ?? '');
        if (!in_array($action, ['acquire', 'adopt', 'keepalive', 'release', 'verify'], true)) {
            throw new \RuntimeException("duo recovery: unsupported exclusion action '$action'");
        }
        self::assertHash((string) ($payload['artifact_hash'] ?? ''), 'request artifact hash');
        self::assertIdentifier((string) ($payload['receipt_id'] ?? ''), 'request receipt id', 32, 64);
        self::assertIdentifier((string) ($payload['target_id'] ?? ''), 'request target id', 32, 32);
        self::assertActor((string) ($payload['owner'] ?? ''), 'request owner');
        self::assertActor((string) ($payload['claimant'] ?? ''), 'request claimant');
        if (!is_int($payload['generation'] ?? null) || (int) $payload['generation'] < 1
            || !is_int($payload['claim_epoch'] ?? null) || (int) $payload['claim_epoch'] < 1) {
            throw new \RuntimeException('duo recovery: request generation/claim_epoch must be positive integers');
        }
        self::assertTimestamp((string) ($payload['timestamp'] ?? ''));

        $status = RollbackControl::status($root);
        if ($action === 'acquire') {
            if (!empty($status['active']) && empty($status['terminal'])) {
                throw new \RuntimeException('duo recovery: exclusion acquisition refused during a nonterminal generation');
            }
            if ((int) $payload['generation'] !== (int) $status['generation'] + 1
                || !hash_equals((string) $payload['target_id'], (string) $status['target_id'])
                || (int) $payload['claim_epoch'] !== 1) {
                throw new \RuntimeException('duo recovery: exclusion acquisition is not for the exact next generation');
            }
            return self::acquire($root, $payload, $status);
        }

        if (empty($status['active'])) {
            throw new \RuntimeException('duo recovery: exclusion action requires an active signed receipt');
        }
        self::assertRequestIdentity($payload, $status);
        if ($action === 'release' && empty($status['terminal'])) {
            throw new \RuntimeException('duo recovery: exclusion release requires committed or rolled_back authority');
        }
        if ($action !== 'release' && !empty($status['terminal'])) {
            throw new \RuntimeException('duo recovery: terminal authority only permits exclusion release');
        }
        return self::mutateHeld($root, $payload, $action);
    }

    /** Called inside RollbackControl's target lock before receipt publication. */
    public static function assertClaimExclusion(string $root, array $receipt, array $event): void {
        self::withLock($root, function () use ($root, $receipt, $event): array {
            $record = self::requiredHeld($root);
            foreach (['artifact_hash', 'generation', 'owner', 'receipt_id', 'target_id'] as $key) {
                if ((string) $record[$key] !== (string) $receipt[$key]) {
                    throw new \RuntimeException("duo recovery: held exclusion $key does not match claim receipt");
                }
            }
            if ((string) $record['claimant'] !== (string) $event['claimant']
                || (int) $record['claim_epoch'] !== (int) $event['claim_epoch']
                || !hash_equals((string) $record['token_sha256'], (string) $receipt['exclusion_token_sha256'])) {
                throw new \RuntimeException('duo recovery: held exclusion is not bound to the exact claim');
            }
            self::callProviderForRecord(self::config($root), $record, 'verify');
            return ['ok' => true];
        });
    }

    /** @return array<string,mixed> */
    public static function execute(
        string $root,
        string $adapter,
        string $operationId,
        int $attempt,
        string $claimant,
        int $claimEpoch,
        string $inputPath
    ): array {
        $config = self::config($root);
        $allowed = self::ADAPTERS;
        if (array_key_exists('code_release_provider', $config)) {
            $allowed[] = 'code_select';
        }
        if (array_key_exists('upload_provider', $config)) {
            $allowed[] = 'storage_apply';
        }
        if (array_key_exists('effect_provider', $config)) {
            $allowed[] = 'effects_inverse';
        }
        if (!in_array($adapter, $allowed, true) || $operationId !== $adapter) {
            throw new \RuntimeException('duo recovery: executor accepts only the exact configured adapter operation id');
        }
        self::assertActor($claimant, 'executor claimant');
        if ($attempt < 1 || $claimEpoch < 1) {
            throw new \RuntimeException('duo recovery: executor attempt/claim_epoch must be positive integers');
        }
        self::assertAbsoluteRegularFile($inputPath, 'executor input');
        self::readCanonical($inputPath, 'executor input');
        $inputHash = hash_file('sha256', $inputPath);
        if (!is_string($inputHash)) {
            throw new \RuntimeException('duo recovery: could not hash executor input');
        }
        $evidence = RollbackControl::activeEvidence($root);
        $status = $evidence['status'];
        if (!empty($status['terminal'])
            || (string) $status['claimant'] !== $claimant
            || (int) $status['claim_epoch'] !== $claimEpoch) {
            throw new \RuntimeException('duo recovery: executor claimant is stale, foreign, or terminal');
        }
        $requiredState = $adapter === 'prior_verify'
            ? 'verifying_prior'
            : (in_array($adapter, ['code_select', 'storage_apply'], true) ? 'promoting' : 'rolling_back');
        if ((string) $status['state'] !== $requiredState) {
            throw new \RuntimeException("duo recovery: $adapter may run only in $requiredState");
        }
        $key = $operationId . '#' . $attempt;
        $prepared = $evidence['open_operations'][$key] ?? null;
        if (!is_array($prepared)
            || !hash_equals((string) ($prepared['input_sha256'] ?? ''), $inputHash)) {
            throw new \RuntimeException('duo recovery: no exact signed prepared operation authorizes this input');
        }
        $record = self::requiredHeld($root);
        self::assertRecordIdentity($record, $status, false);
        self::verifyHeld($root, $record, 'verify');
        if (array_key_exists('code_release_provider', $config)
            && in_array($adapter, ['code_select', 'code_restore'], true)) {
            $release = CodeRelease::execute($root, $adapter, $inputPath, $inputHash, $status);
            return [
                'adapter' => $adapter,
                'adapter_version' => $release['adapter_version'],
                'format' => self::ADAPTER_RESPONSE_FORMAT,
                'input_sha256' => $inputHash,
                'ok' => true,
                'result_sha256' => $release['result_sha256'],
            ];
        }
        if (array_key_exists('checkpoint_provider', $config)
            && in_array($adapter, ['database_restore', 'prior_verify'], true)) {
            $checkpoint = CheckpointBundle::execute($root, $adapter, $inputPath, $inputHash, $status);
            return [
                'adapter' => $adapter,
                'adapter_version' => $checkpoint['adapter_version'],
                'format' => self::ADAPTER_RESPONSE_FORMAT,
                'input_sha256' => $inputHash,
                'ok' => true,
                'result_sha256' => $checkpoint['result_sha256'],
            ];
        }
        if (array_key_exists('upload_provider', $config)
            && in_array($adapter, ['storage_apply', 'storage_restore'], true)) {
            $uploads = UploadBundle::execute($root, $adapter, $inputPath, $inputHash, $status);
            return [
                'adapter' => $adapter,
                'adapter_version' => $uploads['adapter_version'],
                'format' => self::ADAPTER_RESPONSE_FORMAT,
                'input_sha256' => $inputHash,
                'ok' => true,
                'result_sha256' => $uploads['result_sha256'],
            ];
        }
        if (array_key_exists('effect_provider', $config) && $adapter === 'effects_inverse') {
            $effects = EffectBundle::execute($root, $adapter, $inputPath, $inputHash, $status);
            return [
                'adapter' => $adapter,
                'adapter_version' => $effects['adapter_version'],
                'format' => self::ADAPTER_RESPONSE_FORMAT,
                'input_sha256' => $inputHash,
                'ok' => true,
                'result_sha256' => $effects['result_sha256'],
            ];
        }
        $result = self::callAdapter($config, $adapter, [
            'action' => 'execute',
            'adapter' => $adapter,
            'attempt' => $attempt,
            'claim_epoch' => $claimEpoch,
            'claimant' => $claimant,
            'format' => self::ADAPTER_REQUEST_FORMAT,
            'input_path' => $inputPath,
            'input_sha256' => $inputHash,
            'operation_id' => $operationId,
            'receipt_id' => (string) $status['receipt_id'],
            'target_id' => (string) $status['target_id'],
        ], 'completed');
        return [
            'adapter' => $adapter,
            'adapter_version' => (string) $result['adapter_version'],
            'format' => self::ADAPTER_RESPONSE_FORMAT,
            'input_sha256' => $inputHash,
            'ok' => true,
            'result_sha256' => (string) $result['result_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private static function acquire(string $root, array $payload, array $status): array {
        return self::withLock($root, function () use ($root, $payload, $status): array {
            $existing = self::readExclusion($root, false);
            if ($existing !== null) {
                self::validateRecord($existing);
                if (($existing['state'] ?? '') === 'held') {
                    self::assertPayloadRecordMatch($payload, $existing);
                    self::callProviderForRecord(self::config($root), $existing, 'verify');
                    return self::publicRecord($existing);
                }
                self::assertRecordIdentity($existing, $status, true);
                if (empty($status['terminal'])) {
                    throw new \RuntimeException('duo recovery: released exclusion lacks terminal authority');
                }
            }
            $config = self::config($root);
            $response = self::callProvider($config, self::providerRequest($payload, null, 'acquire'), 'held');
            $token = (string) $response['token'];
            if ($token === '' || strlen($token) > 4096 || str_contains($token, "\0")) {
                throw new \RuntimeException('duo recovery: provider returned an invalid opaque token');
            }
            $record = [
                'artifact_hash' => (string) $payload['artifact_hash'],
                'claim_epoch' => (int) $payload['claim_epoch'],
                'claimant' => (string) $payload['claimant'],
                'format' => self::EXCLUSION_FORMAT,
                'generation' => (int) $payload['generation'],
                'owner' => (string) $payload['owner'],
                'provider_id' => (string) $response['provider_id'],
                'provider_version' => (string) $response['provider_version'],
                'receipt_id' => (string) $payload['receipt_id'],
                'state' => 'held',
                'target_id' => (string) $payload['target_id'],
                'token' => $token,
                'token_sha256' => hash('sha256', $token),
                'updated_at' => (string) $payload['timestamp'],
            ];
            self::atomicWrite(self::exclusionPath($root), RollbackControl::canonical($record) . "\n", 0600, 'exclusion record');
            return self::publicRecord($record);
        });
    }

    /** @return array<string,mixed> */
    private static function mutateHeld(string $root, array $payload, string $action): array {
        return self::withLock($root, function () use ($root, $payload, $action): array {
            $record = self::requiredHeld($root);
            self::assertPayloadRecordMatch($payload, $record);
            $providerRecord = $record;
            if ($action === 'adopt') {
                $providerRecord['claimant'] = (string) $payload['claimant'];
                $providerRecord['claim_epoch'] = (int) $payload['claim_epoch'];
            }
            $response = self::callProviderForRecord(self::config($root), $providerRecord, $action);
            $record['provider_version'] = (string) $response['provider_version'];
            $record['updated_at'] = (string) $payload['timestamp'];
            if ($action === 'adopt') {
                $record['claimant'] = (string) $payload['claimant'];
                $record['claim_epoch'] = (int) $payload['claim_epoch'];
            }
            if ($action === 'release') {
                $record['state'] = 'released';
            }
            self::atomicWrite(self::exclusionPath($root), RollbackControl::canonical($record) . "\n", 0600, 'exclusion record');
            return self::publicRecord($record);
        });
    }

    /** @return array<string,mixed> */
    private static function verifyHeld(string $root, array $record, string $action): array {
        return self::withLock($root, function () use ($root, $record, $action): array {
            $current = self::requiredHeld($root);
            if (!hash_equals(RollbackControl::canonical($record), RollbackControl::canonical($current))) {
                throw new \RuntimeException('duo recovery: exclusion record changed concurrently');
            }
            return self::callProviderForRecord(self::config($root), $current, $action);
        });
    }

    /** @return array<string,mixed> */
    private static function callProviderForRecord(array $config, array $record, string $action): array {
        $expected = $action === 'release' ? 'released' : 'held';
        $response = self::callProvider($config, self::providerRequest($record, (string) $record['token'], $action), $expected);
        if (!hash_equals((string) $response['provider_id'], (string) $record['provider_id'])
            || !is_string($response['token'])
            || !hash_equals((string) $record['token'], (string) $response['token'])) {
            throw new \RuntimeException('duo recovery: provider evidence does not match the durable exclusion token');
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private static function callProvider(array $config, array $request, string $expectedState): array {
        $response = self::runProtocol((array) $config['exclusion_provider'], $request, (int) $config['timeout_seconds'], 'exclusion provider');
        self::assertExactKeys($response, [
            'available', 'disconnect_behavior', 'format', 'provider_id', 'provider_version',
            'scopes', 'state', 'target_id', 'token',
        ], 'exclusion provider response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['disconnect_behavior'] ?? '') !== 'remain_excluded'
            || ($response['state'] ?? '') !== $expectedState
            || ($response['scopes'] ?? null) !== self::scopeMap()
            || !hash_equals((string) ($request['target_id'] ?? ''), (string) ($response['target_id'] ?? ''))) {
            throw new \RuntimeException('duo recovery: provider did not attest the complete fail-closed exclusion contract');
        }
        self::assertActor((string) ($response['provider_id'] ?? ''), 'provider id');
        self::assertActor((string) ($response['provider_version'] ?? ''), 'provider version');
        if ($expectedState === 'ready' && $response['token'] !== null) {
            throw new \RuntimeException('duo recovery: provider probe must not mint an exclusion token');
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private static function callAdapter(array $config, string $adapter, array $request, string $expectedStatus): array {
        $response = self::runProtocol((array) $config['adapters'][$adapter], $request, (int) $config['timeout_seconds'], "$adapter adapter");
        self::assertExactKeys($response, [
            'adapter', 'adapter_version', 'available', 'format', 'input_sha256',
            'loads_site_code', 'result_sha256', 'status',
        ], "$adapter adapter response");
        if (($response['format'] ?? '') !== self::ADAPTER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true
            || ($response['loads_site_code'] ?? null) !== false
            || ($response['adapter'] ?? '') !== $adapter
            || ($response['status'] ?? '') !== $expectedStatus
            || ($response['input_sha256'] ?? null) !== ($request['input_sha256'] ?? null)) {
            throw new \RuntimeException("duo recovery: $adapter adapter did not attest the isolated executor contract");
        }
        self::assertActor((string) ($response['adapter_version'] ?? ''), "$adapter adapter version");
        if ($expectedStatus === 'ready') {
            if ($response['result_sha256'] !== null) {
                throw new \RuntimeException("duo recovery: $adapter probe returned a result hash");
            }
        } else {
            self::assertHash((string) ($response['result_sha256'] ?? ''), "$adapter result hash");
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private static function runProtocol(array $command, array $request, int $timeout, string $label): array {
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException("duo recovery: could not start $label");
        }
        $input = RollbackControl::canonical($request) . "\n";
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $observedExit = null;
        $deadline = microtime(true) + $timeout;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (!$state['running']) {
                $observedExit = (int) $state['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                throw new \RuntimeException("duo recovery: $label timed out; exclusion remains held");
            }
            usleep(10000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExit = proc_close($process);
        $exit = $observedExit ?? $closedExit;
        if ($exit !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException("duo recovery: $label failed" . ($detail !== '' ? ': ' . substr($detail, 0, 1000) : ''));
        }
        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("duo recovery: $label returned malformed JSON");
        }
        if (!is_array($decoded) || RollbackControl::canonical($decoded) . "\n" !== $stdout) {
            throw new \RuntimeException("duo recovery: $label returned non-canonical evidence");
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function config(string $root): array {
        if (!self::configured($root)) {
            throw new \RuntimeException('duo recovery: no recovery provider is configured');
        }
        $config = self::readCanonical(self::configPath($root), 'configuration');
        self::validateConfig($config);
        return $config;
    }

    private static function validateConfig(array $config): void {
        $keys = array_keys($config);
        sort($keys, SORT_STRING);
        $expected = ['adapters', 'exclusion_provider', 'format', 'timeout_seconds'];
        foreach (['checkpoint_provider', 'code_release_provider', 'upload_provider', 'effect_provider'] as $optional) {
            if (array_key_exists($optional, $config)) {
                $expected[] = $optional;
            }
        }
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \RuntimeException('duo recovery: configuration has missing or unknown fields');
        }
        if (($config['format'] ?? '') !== self::CONFIG_FORMAT) {
            throw new \RuntimeException('duo recovery: unsupported configuration format');
        }
        self::validateCommand($config['exclusion_provider'] ?? null, 'exclusion provider');
        if (array_key_exists('checkpoint_provider', $config)) {
            self::validateCommand($config['checkpoint_provider'], 'checkpoint provider');
        }
        if (array_key_exists('code_release_provider', $config)) {
            self::validateCommand($config['code_release_provider'], 'code release provider');
        }
        if (array_key_exists('upload_provider', $config)) {
            self::validateCommand($config['upload_provider'], 'upload provider');
        }
        if (array_key_exists('effect_provider', $config)) {
            self::validateCommand($config['effect_provider'], 'effect provider');
        }
        if (!is_array($config['adapters'] ?? null) || array_is_list($config['adapters'])) {
            throw new \RuntimeException('duo recovery: adapters must be an object');
        }
        self::assertExactKeys((array) $config['adapters'], self::ADAPTERS, 'adapters');
        foreach (self::ADAPTERS as $adapter) {
            self::validateCommand($config['adapters'][$adapter] ?? null, "$adapter adapter");
        }
        if (!is_int($config['timeout_seconds'] ?? null)
            || (int) $config['timeout_seconds'] < 1
            || (int) $config['timeout_seconds'] > 60) {
            throw new \RuntimeException('duo recovery: timeout_seconds must be 1..60');
        }
    }

    private static function validateCommand(mixed $command, string $label): void {
        if (!is_array($command) || !array_is_list($command) || $command === []) {
            throw new \RuntimeException("duo recovery: $label must be a non-empty argv array");
        }
        foreach ($command as $arg) {
            if (!is_string($arg) || $arg === '' || str_contains($arg, "\0")) {
                throw new \RuntimeException("duo recovery: $label contains an invalid argv item");
            }
        }
        if ($command[0][0] !== '/' || is_link($command[0]) || !is_file($command[0]) || !is_executable($command[0])) {
            throw new \RuntimeException("duo recovery: $label executable is unavailable or unsafe");
        }
    }

    /** @return array<string,mixed> */
    private static function providerRequest(array $identity, ?string $token, string $action): array {
        return [
            'action' => $action,
            'artifact_hash' => $identity['artifact_hash'] ?? null,
            'claim_epoch' => $identity['claim_epoch'] ?? null,
            'claimant' => $identity['claimant'] ?? null,
            'format' => self::PROVIDER_REQUEST_FORMAT,
            'generation' => $identity['generation'] ?? null,
            'owner' => $identity['owner'] ?? null,
            'receipt_id' => $identity['receipt_id'] ?? null,
            'scopes' => self::scopeMap(),
            'target_id' => $identity['target_id'] ?? null,
            'token' => $token,
        ];
    }

    /** @return array<string,bool> */
    private static function scopeMap(): array {
        return [
            'background_jobs' => true,
            'filesystem_writers' => true,
            'package_updates' => true,
            'public_traffic' => true,
        ];
    }

    private static function assertRequestIdentity(array $payload, array $status): void {
        foreach (['artifact_hash', 'generation', 'owner', 'receipt_id', 'target_id', 'claimant', 'claim_epoch'] as $key) {
            if ((string) $payload[$key] !== (string) $status[$key]) {
                throw new \RuntimeException("duo recovery: exclusion request $key is stale or foreign");
            }
        }
    }

    private static function assertRecordIdentity(array $record, array $status, bool $includeClaim): void {
        $keys = ['artifact_hash', 'generation', 'owner', 'receipt_id', 'target_id'];
        if ($includeClaim) {
            $keys[] = 'claimant';
            $keys[] = 'claim_epoch';
        }
        foreach ($keys as $key) {
            if ((string) $record[$key] !== (string) $status[$key]) {
                throw new \RuntimeException("duo recovery: exclusion record $key does not match authority");
            }
        }
    }

    private static function assertPayloadRecordMatch(array $payload, array $record): void {
        foreach (['artifact_hash', 'generation', 'owner', 'receipt_id', 'target_id'] as $key) {
            if ((string) $payload[$key] !== (string) $record[$key]) {
                throw new \RuntimeException("duo recovery: exclusion $key does not match durable reservation");
            }
        }
        if ((string) $payload['action'] !== 'adopt'
            && ((string) $payload['claimant'] !== (string) $record['claimant']
                || (int) $payload['claim_epoch'] !== (int) $record['claim_epoch'])) {
            throw new \RuntimeException('duo recovery: exclusion claimant is stale or foreign');
        }
        if ((string) $payload['action'] === 'adopt'
            && !(
                ((int) $payload['claim_epoch'] === (int) $record['claim_epoch'] + 1)
                || ((int) $payload['claim_epoch'] === (int) $record['claim_epoch']
                    && (string) $payload['claimant'] === (string) $record['claimant'])
            )) {
            throw new \RuntimeException('duo recovery: exclusion adoption must match or advance one claim epoch');
        }
    }

    /** @return array<string,mixed> */
    private static function requiredHeld(string $root): array {
        $record = self::readExclusion($root, true);
        if (($record['state'] ?? '') !== 'held') {
            throw new \RuntimeException('duo recovery: no held exclusion exists');
        }
        return $record;
    }

    /** @return ?array<string,mixed> */
    private static function readExclusion(string $root, bool $required): ?array {
        $path = self::exclusionPath($root);
        if (!file_exists($path)) {
            if ($required) {
                throw new \RuntimeException('duo recovery: exclusion record is missing');
            }
            return null;
        }
        $record = self::readCanonical($path, 'exclusion record');
        self::validateRecord($record);
        return $record;
    }

    private static function validateRecord(array $record): void {
        self::assertExactKeys($record, [
            'artifact_hash', 'claim_epoch', 'claimant', 'format', 'generation', 'owner',
            'provider_id', 'provider_version', 'receipt_id', 'state', 'target_id',
            'token', 'token_sha256', 'updated_at',
        ], 'exclusion record');
        if (($record['format'] ?? '') !== self::EXCLUSION_FORMAT
            || !in_array((string) ($record['state'] ?? ''), ['held', 'released'], true)) {
            throw new \RuntimeException('duo recovery: exclusion record format/state is invalid');
        }
        self::assertHash((string) $record['artifact_hash'], 'exclusion artifact hash');
        self::assertHash((string) $record['token_sha256'], 'exclusion token hash');
        if (!is_string($record['token']) || $record['token'] === '' || strlen($record['token']) > 4096
            || !hash_equals(hash('sha256', (string) $record['token']), (string) $record['token_sha256'])) {
            throw new \RuntimeException('duo recovery: exclusion token is malformed or tampered');
        }
        self::assertIdentifier((string) $record['receipt_id'], 'exclusion receipt id', 32, 64);
        self::assertIdentifier((string) $record['target_id'], 'exclusion target id', 32, 32);
        foreach (['owner', 'claimant', 'provider_id', 'provider_version'] as $key) {
            self::assertActor((string) $record[$key], "exclusion $key");
        }
        if (!is_int($record['generation']) || $record['generation'] < 1
            || !is_int($record['claim_epoch']) || $record['claim_epoch'] < 1) {
            throw new \RuntimeException('duo recovery: exclusion generation/claim_epoch is invalid');
        }
        self::assertTimestamp((string) $record['updated_at']);
    }

    /** @return array<string,mixed> */
    private static function publicRecord(array $record): array {
        return [
            'claim_epoch' => (int) $record['claim_epoch'],
            'claimant' => (string) $record['claimant'],
            'format' => self::EXCLUSION_FORMAT,
            'generation' => (int) $record['generation'],
            'ok' => true,
            'provider_id' => (string) $record['provider_id'],
            'provider_version' => (string) $record['provider_version'],
            'receipt_id' => (string) $record['receipt_id'],
            'state' => (string) $record['state'],
            'target_id' => (string) $record['target_id'],
            'token_sha256' => (string) $record['token_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private static function readCanonical(string $path, string $label): array {
        self::assertAbsoluteRegularFile($path, $label);
        $raw = file_get_contents($path);
        try {
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("duo recovery: $label is malformed JSON");
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || RollbackControl::canonical($decoded) . "\n" !== $raw) {
            throw new \RuntimeException("duo recovery: $label must be canonical JSON with one trailing newline");
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function withLock(string $root, callable $callback): array {
        $path = $root . '/recovery.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo recovery: recovery lock path is unsafe');
        }
        $lock = @fopen($path, 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('duo recovery: could not open recovery lock');
        }
        @chmod($path, 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('duo recovery: could not acquire recovery lock');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function atomicWrite(string $path, string $bytes, int $mode, string $label): void {
        $dir = dirname($path);
        if (!is_dir($dir) || is_link($dir)) {
            throw new \RuntimeException("duo recovery: unsafe $label directory");
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException("duo recovery: unsafe $label path");
        }
        $tmp = tempnam($dir, '.duo-recovery-');
        if ($tmp === false) {
            throw new \RuntimeException("duo recovery: could not allocate $label temp file");
        }
        try {
            @chmod($tmp, $mode);
            $handle = fopen($tmp, 'wb');
            if (!is_resource($handle) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException("duo recovery: could not durably write $label");
            }
            fclose($handle);
            if (!rename($tmp, $path)) {
                throw new \RuntimeException("duo recovery: could not publish $label");
            }
            @chmod($path, $mode);
            $dirHandle = fopen($dir, 'r');
            if (!is_resource($dirHandle) || !fsync($dirHandle)) {
                throw new \RuntimeException("duo recovery: could not fsync $label directory");
            }
            fclose($dirHandle);
            if (file_get_contents($path) !== $bytes) {
                throw new \RuntimeException("duo recovery: $label readback mismatch");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function assertAbsoluteRegularFile(string $path, string $label): void {
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo recovery: $label must be an absolute regular file");
        }
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo recovery: $label has missing or unknown fields");
        }
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new \RuntimeException("duo recovery: $label must be a sha256 hex digest");
        }
    }

    private static function assertIdentifier(string $value, string $label, int $min, int $max): void {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new \RuntimeException("duo recovery: $label is malformed");
        }
    }

    private static function assertActor(string $value, string $label): void {
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9._:@+\/-]+$/', $value) !== 1) {
            throw new \RuntimeException("duo recovery: $label is malformed");
        }
    }

    private static function assertTimestamp(string $value): void {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \RuntimeException('duo recovery: timestamp must be canonical UTC seconds');
        }
    }

    private static function configPath(string $root): string {
        return $root . '/recovery-config.json';
    }

    private static function exclusionPath(string $root): string {
        return $root . '/exclusion.json';
    }
}
