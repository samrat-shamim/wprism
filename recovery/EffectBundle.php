<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Receipt-owned lifecycle/rebuild effect preparation and inverse execution.
 *
 * The compiled inventory is the entire authority. A target provider may
 * retain before-images, isolate mail/HTTP/queue calls into an outbox, and run
 * version-pinned inverses, but it may never add an effect after preparation.
 * Provider stdout/stderr is redacted on failure and every successful response
 * is canonical, secret-free evidence bound to one target generation.
 */
final class EffectBundle {
    private const REQUEST_FORMAT = 'duo-effect-bundle-request/v1';
    private const PROVIDER_REQUEST_FORMAT = 'duo-effect-provider-request/v1';
    private const PROVIDER_RESPONSE_FORMAT = 'duo-effect-provider-response/v1';
    private const INVENTORY_FORMAT = 'duo-effect-compile-inventory/v1';
    private const PRIOR_FORMAT = 'duo-effect-prior-evidence/v1';
    private const METADATA_FORMAT = 'duo-effect-bundle-metadata/v1';
    private const OPERATION_FORMAT = 'duo-effect-operation/v1';

    public static function configured(string $root): bool {
        return array_key_exists('effect_provider', RecoveryExecutor::configuration($root));
    }

    /** @return array<string,mixed> */
    public static function probe(string $root): array {
        $config = RecoveryExecutor::configuration($root);
        if (!array_key_exists('effect_provider', $config)) {
            throw new \RuntimeException('duo effects: no effect provider is configured');
        }
        $status = RollbackControl::status($root);
        $response = self::call($config, self::providerRequest([
            'action' => 'probe', 'artifact_directory' => null, 'artifact_hash' => null,
            'claim_epoch' => null, 'claimant' => null, 'generation' => (int) $status['generation'],
            'input_path' => null, 'input_sha256' => null, 'inventory_path' => null,
            'inventory_sha256' => null, 'metadata_sha256' => null, 'owner' => null,
            'prior_evidence_path' => null, 'prior_evidence_sha256' => null,
            'receipt_id' => null, 'target_id' => (string) $status['target_id'],
        ]));
        self::assertExactKeys($response, [
            'available', 'credentials_exposed', 'format', 'inverse_readback',
            'outbox_prevention', 'provider_id', 'provider_version', 'state',
            'supports_database_checkpoint', 'supports_external',
        ], 'probe response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['available'] ?? null) !== true || ($response['state'] ?? '') !== 'ready'
            || ($response['inverse_readback'] ?? null) !== true
            || ($response['outbox_prevention'] ?? null) !== true
            || ($response['supports_database_checkpoint'] ?? null) !== true
            || ($response['supports_external'] ?? null) !== true
            || ($response['credentials_exposed'] ?? null) !== false) {
            throw new \RuntimeException('duo effects: provider did not attest inverse, readback, and outbox isolation');
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
            'effect bundle request'
        );
        self::validateRequest($payload);
        return self::withLock($root, function () use ($root, $payload): array {
            $status = RollbackControl::status($root);
            if (!empty($status['active']) && empty($status['terminal'])) {
                throw new \RuntimeException('duo effects: prepare refused during a nonterminal generation');
            }
            if ((int) $payload['generation'] !== (int) $status['generation'] + 1
                || (int) $payload['claim_epoch'] !== 1
                || !hash_equals((string) $payload['target_id'], (string) $status['target_id'])) {
                throw new \RuntimeException('duo effects: prepare is not for the exact next target generation');
            }
            $recovery = RecoveryExecutor::decorateStatus($root, $status);
            $reservation = $recovery['exclusion_reservation'] ?? null;
            if (($recovery['exclusion_state'] ?? '') !== 'held' || !is_array($reservation)) {
                throw new \RuntimeException('duo effects: prepare requires verified maintenance exclusion');
            }
            foreach (['artifact_hash', 'claim_epoch', 'claimant', 'generation', 'owner', 'receipt_id'] as $key) {
                if ((string) $payload[$key] !== (string) ($reservation[$key] ?? '')) {
                    throw new \RuntimeException("duo effects: exclusion reservation $key does not match prepare");
                }
            }
            return self::prepare($root, $payload);
        });
    }

    /** Called under RollbackControl's target lock before receipt publication. */
    public static function assertClaimEffectBundle(string $root, array $receipt, array $event): void {
        $metadata = self::metadata($root, (string) $receipt['receipt_id']);
        self::assertIdentity($metadata, $receipt, false);
        if ((string) $metadata['claimant'] !== (string) $event['claimant']
            || (int) $metadata['claim_epoch'] !== (int) $event['claim_epoch']
            || !hash_equals(self::metadataHash($metadata), (string) $receipt['lifecycle_receipts_sha256'])
            || (string) $metadata['created_at'] !== (string) $receipt['created_at']
            || (string) $metadata['retention_until'] !== (string) $receipt['retention_until']) {
            throw new \RuntimeException('duo effects: prepared bundle is not bound to the exact claim receipt');
        }
        self::verifyArtifacts($root, $metadata);
    }

    /**
     * Reconcile one actual lifecycle/rebuilder effect against the immutable
     * inventory. Callers invoke this before a prevented effect escapes, or
     * immediately after a restorable/reversible mutation. Unknown effects
     * fail without broadening receipt authority.
     *
     * @return array<string,mixed>
     */
    public static function observe(string $root, array $status, array $actual): array {
        if (empty($status['active']) || !empty($status['terminal'])) {
            throw new \RuntimeException('duo effects: observation requires active nonterminal authority');
        }
        self::assertExactKeys($actual, ['effect_id', 'kind', 'manifest', 'phase', 'selector'], 'actual effect');
        self::rejectSecrets($actual, 'actual effect');
        $metadata = self::metadata($root, (string) ($status['receipt_id'] ?? ''));
        self::assertIdentity($metadata, $status, false);
        self::verifyArtifacts($root, $metadata);
        if (!is_string($status['lifecycle_receipts_sha256'] ?? null)
            || !hash_equals(self::metadataHash($metadata), (string) $status['lifecycle_receipts_sha256'])) {
            throw new \RuntimeException('duo effects: active receipt does not authorize this effect bundle');
        }
        $inventory = self::inventory($root, $metadata);
        $declared = null;
        foreach ($inventory['effects'] as $row) {
            $effect = $row['effect'];
            if ((string) $row['manifest'] === (string) $actual['manifest']
                && (string) $row['phase'] === (string) $actual['phase']
                && (string) $effect['id'] === (string) $actual['effect_id']) {
                $declared = $row;
                break;
            }
        }
        if ($declared === null
            || (string) $declared['effect']['kind'] !== (string) $actual['kind']
            || $declared['effect']['selector'] !== $actual['selector']) {
            throw new \RuntimeException('duo effects: actual effect is undeclared or exceeds its bounded selector');
        }
        $config = RecoveryExecutor::configuration($root);
        $response = self::call($config, self::providerOperationRequest(
            $root,
            $metadata,
            $status,
            'observe',
            null,
            null,
            ['actual_effect' => $actual]
        ));
        self::assertExactKeys($response, [
            'credentials_exposed', 'effect_event_sha256', 'format', 'mode',
            'outbox_receipt_sha256', 'prevented', 'provider_id', 'provider_version', 'state',
        ], 'observation response');
        self::assertProviderIdentity($metadata, $response);
        $mode = (string) $declared['effect']['mode'];
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== 'observed'
            || ($response['credentials_exposed'] ?? null) !== false
            || (string) ($response['mode'] ?? '') !== $mode) {
            throw new \RuntimeException('duo effects: provider observation does not match the prepared declaration');
        }
        self::assertHash((string) ($response['effect_event_sha256'] ?? ''), 'effect event hash');
        if ($mode === 'prevented') {
            if (($response['prevented'] ?? null) !== true) {
                throw new \RuntimeException('duo effects: reporting-only observation is not prevention');
            }
            self::assertHash((string) ($response['outbox_receipt_sha256'] ?? ''), 'outbox receipt hash');
        } elseif (($response['prevented'] ?? null) !== false || $response['outbox_receipt_sha256'] !== null) {
            throw new \RuntimeException('duo effects: non-prevented effect returned false outbox evidence');
        }
        return ['ok' => true] + $response;
    }

    /** @return array{adapter_version:string,result_sha256:string} */
    public static function execute(
        string $root,
        string $adapter,
        string $inputPath,
        string $inputHash,
        array $status
    ): array {
        if ($adapter !== 'effects_inverse') {
            throw new \RuntimeException('duo effects: unsupported inverse operation');
        }
        $input = self::readCanonical($inputPath, 'operation input');
        self::assertExactKeys($input, [
            'artifact_hash', 'effects_inventory_sha256', 'format', 'generation',
            'lifecycle_receipts_sha256', 'operation', 'owner', 'prior_evidence_sha256',
            'receipt_id', 'target_id',
        ], 'operation input');
        if (($input['format'] ?? '') !== self::OPERATION_FORMAT || ($input['operation'] ?? '') !== 'restore_prior') {
            throw new \RuntimeException('duo effects: operation input does not authorize prior restoration');
        }
        self::assertIdentity($input, $status, false);
        $metadata = self::metadata($root, (string) $status['receipt_id']);
        self::assertIdentity($metadata, $status, false);
        self::verifyArtifacts($root, $metadata);
        foreach (['effects_inventory_sha256', 'prior_evidence_sha256'] as $key) {
            if (!hash_equals((string) $metadata[$key], (string) $input[$key])) {
                throw new \RuntimeException("duo effects: operation $key differs from prepared evidence");
            }
        }
        if (!hash_equals(self::metadataHash($metadata), (string) $input['lifecycle_receipts_sha256'])
            || !hash_equals(self::metadataHash($metadata), (string) $status['lifecycle_receipts_sha256'])) {
            throw new \RuntimeException('duo effects: signed receipt does not authorize this effect bundle');
        }
        $config = RecoveryExecutor::configuration($root);
        $request = self::providerOperationRequest($root, $metadata, $status, 'inverse', $inputPath, $inputHash);
        $restored = self::call($config, $request);
        self::validateRestore($metadata, $restored, 'restored');
        $request['action'] = 'verify-prior';
        $verified = self::call($config, $request);
        self::validateRestore($metadata, $verified, 'verified');
        if (!hash_equals((string) $restored['result_sha256'], (string) $verified['result_sha256'])) {
            throw new \RuntimeException('duo effects: restored resources changed during fresh verification');
        }
        return [
            'adapter_version' => (string) $verified['provider_version'],
            'result_sha256' => (string) $verified['result_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    public static function statusEvidence(string $root, string $receiptId): array {
        $metadata = self::metadata($root, $receiptId);
        self::verifyArtifacts($root, $metadata);
        return [
            'effects_inventory_sha256' => (string) $metadata['effects_inventory_sha256'],
            'metadata_sha256' => self::metadataHash($metadata),
            'prior_evidence_sha256' => (string) $metadata['prior_evidence_sha256'],
            'provider_id' => (string) $metadata['provider_id'],
            'provider_version' => (string) $metadata['provider_version'],
        ];
    }

    /** @return array<string,mixed> */
    private static function prepare(string $root, array $payload): array {
        $inventory = ['effects' => $payload['inventory'], 'format' => self::INVENTORY_FORMAT];
        self::validateInventory($inventory);
        foreach ($inventory['effects'] as $row) {
            if (($row['effect']['mode'] ?? '') === 'irreversible') {
                throw new \RuntimeException(
                    "duo effects: automatic profile blocked by irreversible effect '{$row['manifest']}:{$row['effect']['id']}'"
                );
            }
        }
        $dir = self::receiptDirectory($root, (string) $payload['receipt_id']);
        self::ensureDirectory($dir, 0700);
        self::ensureDirectory($dir . '/artifacts', 0700);
        $metadataPath = $dir . '/effect-bundle-metadata.json';
        $inventoryBytes = RollbackControl::canonical($inventory) . "\n";
        $inventoryHash = hash('sha256', $inventoryBytes);
        if (is_file($metadataPath)) {
            $metadata = self::readCanonical($metadataPath, 'effect bundle metadata');
            self::validateMetadata($metadata);
            self::assertIdentity($payload, $metadata, true);
            if (!hash_equals($inventoryHash, (string) $metadata['effects_inventory_sha256'])) {
                throw new \RuntimeException('duo effects: prepare retry changed the compiled inventory');
            }
            self::verifyArtifacts($root, $metadata);
            return self::publicMetadata($metadata);
        }
        $inventoryPath = $dir . '/artifacts/effects-inventory.json';
        $priorPath = $dir . '/artifacts/prior-effect-evidence.json';
        self::publishExact($inventoryPath, $inventoryBytes, 0600, 'effects inventory');
        if (is_link($priorPath) || (file_exists($priorPath) && !is_file($priorPath))) {
            throw new \RuntimeException('duo effects: prior evidence output path is unsafe');
        }
        if (is_file($priorPath) && !@unlink($priorPath)) {
            throw new \RuntimeException('duo effects: could not clear interrupted prior evidence');
        }
        $config = RecoveryExecutor::configuration($root);
        $response = self::call($config, self::providerRequest([
            'action' => 'prepare', 'artifact_directory' => $dir . '/artifacts',
            'artifact_hash' => (string) $payload['artifact_hash'], 'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'], 'generation' => (int) $payload['generation'],
            'input_path' => null, 'input_sha256' => null, 'inventory_path' => $inventoryPath,
            'inventory_sha256' => $inventoryHash, 'metadata_sha256' => null,
            'owner' => (string) $payload['owner'], 'prior_evidence_path' => $priorPath,
            'prior_evidence_sha256' => null, 'receipt_id' => (string) $payload['receipt_id'],
            'target_id' => (string) $payload['target_id'],
        ]));
        self::assertExactKeys($response, [
            'credentials_exposed', 'format', 'inverse_readback', 'inventory_sha256',
            'outbox_prevention', 'prior_evidence_sha256', 'provider_id', 'provider_version',
            'receipt_inputs_sha256', 'state', 'unsupported_effects',
        ], 'prepare response');
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== 'prepared'
            || ($response['inverse_readback'] ?? null) !== true
            || ($response['outbox_prevention'] ?? null) !== true
            || ($response['credentials_exposed'] ?? null) !== false
            || ($response['unsupported_effects'] ?? null) !== []
            || !hash_equals($inventoryHash, (string) ($response['inventory_sha256'] ?? ''))) {
            throw new \RuntimeException('duo effects: provider could not prepare every declared effect before mutation');
        }
        self::assertAbsoluteRegularFile($priorPath, 'prior effect evidence');
        $prior = self::readCanonical($priorPath, 'prior effect evidence');
        self::validatePrior($prior, $inventory);
        $priorHash = hash_file('sha256', $priorPath);
        if (!is_string($priorHash)
            || !hash_equals($priorHash, (string) $response['prior_evidence_sha256'])) {
            throw new \RuntimeException('duo effects: provider prior evidence does not match its artifact');
        }
        self::assertHash((string) ($response['receipt_inputs_sha256'] ?? ''), 'receipt inputs hash');
        $metadata = [
            'artifact_hash' => (string) $payload['artifact_hash'], 'claim_epoch' => (int) $payload['claim_epoch'],
            'claimant' => (string) $payload['claimant'], 'created_at' => (string) $payload['timestamp'],
            'effects_inventory_path' => 'artifacts/effects-inventory.json',
            'effects_inventory_sha256' => $inventoryHash, 'format' => self::METADATA_FORMAT,
            'generation' => (int) $payload['generation'], 'owner' => (string) $payload['owner'],
            'prior_evidence_path' => 'artifacts/prior-effect-evidence.json',
            'prior_evidence_sha256' => $priorHash, 'provider_id' => (string) $response['provider_id'],
            'provider_version' => (string) $response['provider_version'],
            'receipt_id' => (string) $payload['receipt_id'],
            'receipt_inputs_sha256' => (string) $response['receipt_inputs_sha256'],
            'retention_until' => (string) $payload['retention_until'], 'target_id' => (string) $payload['target_id'],
        ];
        self::validateMetadata($metadata);
        self::publishExact($metadataPath, RollbackControl::canonical($metadata) . "\n", 0600, 'effect bundle metadata');
        return self::publicMetadata($metadata);
    }

    private static function validateInventory(array $inventory): void {
        self::assertExactKeys($inventory, ['effects', 'format'], 'effects inventory');
        if (($inventory['format'] ?? '') !== self::INVENTORY_FORMAT
            || !is_array($inventory['effects'] ?? null) || !array_is_list($inventory['effects'])
            || $inventory['effects'] === []) {
            throw new \RuntimeException('duo effects: compiled effects inventory is missing or malformed');
        }
        $seen = [];
        foreach ($inventory['effects'] as $row) {
            if (!is_array($row) || array_is_list($row)) throw new \RuntimeException('duo effects: malformed inventory row');
            self::assertExactKeys($row, ['effect', 'manifest', 'phase', 'source'], 'inventory row');
            self::assertActor((string) $row['manifest'], 'manifest');
            if (!in_array($row['phase'] ?? null, ['lifecycle', 'rebuild', 'regenerator'], true)
                || !is_string($row['source']) || $row['source'] === '') {
                throw new \RuntimeException('duo effects: inventory phase/source is malformed');
            }
            $effect = $row['effect'];
            if (!is_array($effect) || array_is_list($effect)) throw new \RuntimeException('duo effects: effect declaration is malformed');
            $mode = $effect['mode'] ?? null;
            $expected = ['id', 'kind', 'mode', 'selector'];
            if ($mode === 'reversible') $expected[] = 'adapter';
            if ($mode === 'prevented') $expected[] = 'prevention';
            self::assertExactKeys($effect, $expected, 'effect declaration');
            if (!is_string($effect['id'] ?? null) || preg_match('/^[a-z][a-z0-9._:-]{0,127}$/', $effect['id']) !== 1
                || !in_array($effect['kind'] ?? null, ['database','filesystem','schedule','cache','queue','mail','http','external'], true)
                || !in_array($mode, ['restorable','reversible','prevented','irreversible'], true)) {
                throw new \RuntimeException('duo effects: effect identity/kind/mode is malformed');
            }
            $selector = $effect['selector'] ?? null;
            if (!is_array($selector) || array_is_list($selector)) throw new \RuntimeException('duo effects: selector is malformed');
            self::assertExactKeys($selector, ['scope','type','value'], 'effect selector');
            if (!in_array($selector['scope'] ?? null, ['database_checkpoint','external'], true)
                || !in_array($selector['type'] ?? null, ['table','option','path','hook','namespace','queue','mail_subject','url_prefix','provider_resource','plugin_lifecycle'], true)
                || !is_string($selector['value'] ?? null) || $selector['value'] === ''
                || strlen($selector['value']) > 512
                || preg_match('/[\x00-\x1f\x7f*]/', $selector['value']) === 1
                || preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $selector['value']) === 1) {
                throw new \RuntimeException('duo effects: selector is unbounded or malformed');
            }
            if ($selector['type'] === 'path' && (str_starts_with($selector['value'], '/')
                || str_contains($selector['value'], '\\') || in_array('.', explode('/', $selector['value']), true)
                || in_array('..', explode('/', $selector['value']), true))) {
                throw new \RuntimeException('duo effects: selector path is not bounded and traversal-free');
            }
            if ($selector['type'] === 'url_prefix'
                && (!str_starts_with($selector['value'], 'https://') || str_contains($selector['value'], '?'))) {
                throw new \RuntimeException('duo effects: URL selector is not bounded HTTPS');
            }
            if ($effect['kind'] === 'database'
                && ($selector['scope'] !== 'database_checkpoint' || !in_array($selector['type'], ['table','option'], true))) {
                throw new \RuntimeException('duo effects: database effect lacks exact checkpoint coverage');
            }
            if ($effect['kind'] !== 'database' && $selector['scope'] !== 'external') {
                throw new \RuntimeException('duo effects: non-database effect is not explicitly external');
            }
            if ($mode === 'restorable' && $selector['scope'] !== 'database_checkpoint') {
                throw new \RuntimeException('duo effects: restorable effect lacks checkpoint coverage');
            }
            if ($mode === 'reversible') {
                $adapter = $effect['adapter'] ?? null;
                if (!is_array($adapter) || array_is_list($adapter)) throw new \RuntimeException('duo effects: reversible effect lacks adapter');
                self::assertExactKeys($adapter, ['id','inverse','inverse_inputs','verifier','verifier_inputs','version'], 'effect adapter');
                foreach (['id','inverse','verifier'] as $key) {
                    if (!is_string($adapter[$key] ?? null)
                        || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', (string) $adapter[$key]) !== 1) {
                        throw new \RuntimeException('duo effects: reversible effect adapter identity is malformed');
                    }
                }
                if (!is_string($adapter['version'] ?? null)
                    || preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+)+$/', $adapter['version']) !== 1) {
                    throw new \RuntimeException('duo effects: reversible effect adapter version is not exactly pinned');
                }
                foreach (['inverse_inputs','verifier_inputs'] as $key) {
                    $inputs = $adapter[$key] ?? null;
                    if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []
                        || count(array_unique($inputs)) !== count($inputs)) {
                        throw new \RuntimeException('duo effects: reversible effect lacks exact receipt inputs');
                    }
                    foreach ($inputs as $input) {
                        if (!is_string($input) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $input) !== 1
                            || preg_match('/secret|credential|password|authorization|token|api_?key/i', $input) === 1) {
                            throw new \RuntimeException('duo effects: reversible effect receipt input is malformed');
                        }
                    }
                }
            }
            if ($mode === 'prevented'
                && (!in_array($effect['kind'], ['mail','http','queue'], true)
                    || ($effect['prevention'] ?? null) !== 'receipt_outbox')) {
                throw new \RuntimeException('duo effects: prevented effect lacks compatible receipt-bound outbox isolation');
            }
            if ($selector['type'] === 'plugin_lifecycle' && $mode !== 'irreversible') {
                throw new \RuntimeException('duo effects: generic plugin lifecycle cannot claim automatic reversibility');
            }
            $key = $row['manifest'] . ':' . $effect['id'];
            if (isset($seen[$key])) throw new \RuntimeException('duo effects: duplicate effect declaration');
            $seen[$key] = true;
            self::rejectSecrets($row, 'effects inventory');
        }
    }

    private static function validatePrior(array $prior, array $inventory): void {
        self::assertExactKeys($prior, ['effects', 'format'], 'prior effect evidence');
        if (($prior['format'] ?? '') !== self::PRIOR_FORMAT
            || !is_array($prior['effects'] ?? null) || !array_is_list($prior['effects'])) {
            throw new \RuntimeException('duo effects: prior evidence is malformed');
        }
        $declared = [];
        foreach ($inventory['effects'] as $row) $declared[$row['manifest'] . ':' . $row['effect']['id']] = $row;
        $seen = [];
        foreach ($prior['effects'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) throw new \RuntimeException('duo effects: malformed prior effect row');
            self::assertExactKeys($entry, ['effect_id','inverse_input_sha256','manifest','mode','outbox_id','prior_sha256','verifier_input_sha256'], 'prior effect row');
            $key = (string) $entry['manifest'] . ':' . (string) $entry['effect_id'];
            if (isset($seen[$key]) || !isset($declared[$key]) || (string) $entry['mode'] !== (string) $declared[$key]['effect']['mode']) {
                throw new \RuntimeException('duo effects: prior evidence contains a duplicate, undeclared, or mode-changed effect');
            }
            $seen[$key] = true;
            $mode = (string) $entry['mode'];
            if ($mode === 'reversible') {
                foreach (['inverse_input_sha256','prior_sha256','verifier_input_sha256'] as $hash) self::assertHash((string) $entry[$hash], "prior $hash");
                if ($entry['outbox_id'] !== null) throw new \RuntimeException('duo effects: reversible evidence contains outbox authority');
            } elseif ($mode === 'restorable') {
                self::assertHash((string) $entry['prior_sha256'], 'checkpoint coverage hash');
                if ($entry['inverse_input_sha256'] !== null || $entry['verifier_input_sha256'] !== null || $entry['outbox_id'] !== null) throw new \RuntimeException('duo effects: restorable evidence exceeds checkpoint authority');
            } elseif ($mode === 'prevented') {
                self::assertActor((string) $entry['outbox_id'], 'outbox id');
                if ($entry['inverse_input_sha256'] !== null || $entry['prior_sha256'] !== null || $entry['verifier_input_sha256'] !== null) throw new \RuntimeException('duo effects: prevented evidence contains inverse authority');
            } else {
                throw new \RuntimeException('duo effects: irreversible effect reached prepared evidence');
            }
        }
        if (count($seen) !== count($declared)) throw new \RuntimeException('duo effects: prior evidence is incomplete');
    }

    private static function validateRestore(array $metadata, array $response, string $state): void {
        self::assertExactKeys($response, ['credentials_exposed','format','provider_id','provider_version','result_sha256','state','undeclared_effects'], "$state response");
        self::assertProviderIdentity($metadata, $response);
        if (($response['format'] ?? '') !== self::PROVIDER_RESPONSE_FORMAT
            || ($response['state'] ?? '') !== $state
            || ($response['credentials_exposed'] ?? null) !== false
            || ($response['undeclared_effects'] ?? null) !== []) {
            throw new \RuntimeException("duo effects: provider did not return complete $state evidence");
        }
        self::assertHash((string) ($response['result_sha256'] ?? ''), "$state result hash");
    }

    /** @return array<string,mixed> */
    private static function inventory(string $root, array $metadata): array {
        $inventory = self::readCanonical(self::artifactPath($root, $metadata, (string) $metadata['effects_inventory_path']), 'effects inventory');
        self::validateInventory($inventory);
        return $inventory;
    }

    private static function verifyArtifacts(string $root, array $metadata): void {
        self::validateMetadata($metadata);
        $inventoryPath = self::artifactPath($root, $metadata, (string) $metadata['effects_inventory_path']);
        $priorPath = self::artifactPath($root, $metadata, (string) $metadata['prior_evidence_path']);
        if (!hash_equals((string) $metadata['effects_inventory_sha256'], (string) hash_file('sha256', $inventoryPath))
            || !hash_equals((string) $metadata['prior_evidence_sha256'], (string) hash_file('sha256', $priorPath))) {
            throw new \RuntimeException('duo effects: prepared artifacts changed after receipt binding');
        }
        $inventory = self::readCanonical($inventoryPath, 'effects inventory'); self::validateInventory($inventory);
        $prior = self::readCanonical($priorPath, 'prior effect evidence'); self::validatePrior($prior, $inventory);
    }

    private static function validateRequest(array $payload): void {
        self::assertExactKeys($payload, ['action','artifact_hash','claim_epoch','claimant','format','generation','inventory','owner','receipt_id','retention_until','target_id','timestamp'], 'request');
        if (($payload['format'] ?? '') !== self::REQUEST_FORMAT || ($payload['action'] ?? '') !== 'prepare'
            || !is_array($payload['inventory'] ?? null)) throw new \RuntimeException('duo effects: unsupported prepare request');
        self::assertHash((string) $payload['artifact_hash'], 'artifact hash'); self::assertActor((string) $payload['owner'], 'owner'); self::assertActor((string) $payload['claimant'], 'claimant');
        self::assertIdentifier((string) $payload['receipt_id'], 'receipt id', 32, 64); self::assertIdentifier((string) $payload['target_id'], 'target id', 32, 32);
        if (!is_int($payload['generation']) || $payload['generation'] < 1 || !is_int($payload['claim_epoch']) || $payload['claim_epoch'] < 1) throw new \RuntimeException('duo effects: generation/claim_epoch must be positive integers');
        $created = self::timeValue((string) $payload['timestamp']); $retention = self::timeValue((string) $payload['retention_until']);
        if ($retention <= $created) throw new \RuntimeException('duo effects: retention deadline must follow preparation');
    }

    private static function validateMetadata(array $m): void {
        self::assertExactKeys($m, ['artifact_hash','claim_epoch','claimant','created_at','effects_inventory_path','effects_inventory_sha256','format','generation','owner','prior_evidence_path','prior_evidence_sha256','provider_id','provider_version','receipt_id','receipt_inputs_sha256','retention_until','target_id'], 'metadata');
        if (($m['format'] ?? '') !== self::METADATA_FORMAT) throw new \RuntimeException('duo effects: malformed metadata');
        foreach (['artifact_hash','effects_inventory_sha256','prior_evidence_sha256','receipt_inputs_sha256'] as $key) self::assertHash((string) $m[$key], "metadata $key");
        self::assertActor((string) $m['provider_id'], 'provider id'); self::assertActor((string) $m['provider_version'], 'provider version');
        self::timeValue((string) $m['created_at']); self::timeValue((string) $m['retention_until']); self::rejectSecrets($m, 'metadata');
    }

    /** @return array<string,mixed> */
    private static function providerRequest(array $values): array { return ['format' => self::PROVIDER_REQUEST_FORMAT] + $values; }

    /** @return array<string,mixed> */
    private static function providerOperationRequest(string $root, array $metadata, array $status, string $action, ?string $inputPath, ?string $inputHash, array $extra = []): array {
        return self::providerRequest([
            'action' => $action, 'artifact_directory' => dirname(self::artifactPath($root, $metadata, (string) $metadata['effects_inventory_path'])),
            'artifact_hash' => (string) $metadata['artifact_hash'], 'claim_epoch' => (int) $status['claim_epoch'],
            'claimant' => (string) $status['claimant'], 'generation' => (int) $metadata['generation'],
            'input_path' => $inputPath, 'input_sha256' => $inputHash,
            'inventory_path' => self::artifactPath($root, $metadata, (string) $metadata['effects_inventory_path']),
            'inventory_sha256' => (string) $metadata['effects_inventory_sha256'],
            'metadata_sha256' => self::metadataHash($metadata), 'owner' => (string) $metadata['owner'],
            'prior_evidence_path' => self::artifactPath($root, $metadata, (string) $metadata['prior_evidence_path']),
            'prior_evidence_sha256' => (string) $metadata['prior_evidence_sha256'],
            'receipt_id' => (string) $metadata['receipt_id'], 'target_id' => (string) $metadata['target_id'],
        ] + $extra);
    }

    private static function assertIdentity(array $a, array $b, bool $claimant): void {
        foreach (['artifact_hash','generation','owner','receipt_id','target_id'] as $key) if ((string) ($a[$key] ?? '') !== (string) ($b[$key] ?? '')) throw new \RuntimeException("duo effects: identity $key mismatch");
        if ($claimant && ((string) ($a['claimant'] ?? '') !== (string) ($b['claimant'] ?? '') || (int) ($a['claim_epoch'] ?? 0) !== (int) ($b['claim_epoch'] ?? 0))) throw new \RuntimeException('duo effects: claimant identity mismatch');
    }
    private static function assertProviderIdentity(array $metadata, array $response): void { if (!hash_equals((string) $metadata['provider_id'], (string) ($response['provider_id'] ?? '')) || !hash_equals((string) $metadata['provider_version'], (string) ($response['provider_version'] ?? ''))) throw new \RuntimeException('duo effects: provider identity changed after preparation'); }
    /** @return array<string,mixed> */ private static function publicMetadata(array $m): array { return ['effects_inventory_sha256'=>$m['effects_inventory_sha256'],'lifecycle_receipts_sha256'=>self::metadataHash($m),'ok'=>true,'prior_evidence_sha256'=>$m['prior_evidence_sha256'],'provider_id'=>$m['provider_id'],'provider_version'=>$m['provider_version'],'receipt_inputs_sha256'=>$m['receipt_inputs_sha256']]; }
    private static function metadataHash(array $m): string { return hash('sha256', RollbackControl::canonical($m)); }
    /** @return array<string,mixed> */ private static function metadata(string $root,string $id): array { $m=self::readCanonical(self::receiptDirectory($root,$id).'/effect-bundle-metadata.json','effect bundle metadata');self::validateMetadata($m);return $m; }

    /** @return array<string,mixed> */
    private static function call(array $config, array $request): array {
        $command = $config['effect_provider'] ?? null;
        if (!is_array($command) || $command === []) throw new \RuntimeException('duo effects: provider is unavailable');
        $spec = [['pipe','r'],['pipe','w'],['pipe','w']]; $pipes=[]; $process=@proc_open($command,$spec,$pipes,null,[]);
        if (!is_resource($process)) throw new \RuntimeException('duo effects: could not start provider');
        fwrite($pipes[0], RollbackControl::canonical($request)."\n"); fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$deadline=microtime(true)+(int)$config['timeout_seconds'];$exit=null;
        while(true){$stdout.=(string)stream_get_contents($pipes[1]);$stderr.=(string)stream_get_contents($pipes[2]);if(strlen($stdout)+strlen($stderr)>1048576){@proc_terminate($process,9);throw new \RuntimeException('duo effects: provider output exceeded redacted limit');}$state=proc_get_status($process);if(!$state['running']){$exit=(int)$state['exitcode'];break;}if(microtime(true)>=$deadline){@proc_terminate($process,9);throw new \RuntimeException('duo effects: provider timed out; exclusion remains held');}usleep(10000);}
        $stdout.=(string)stream_get_contents($pipes[1]);$stderr.=(string)stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);$exit=$exit??$closed;
        if($exit!==0)throw new \RuntimeException('duo effects: provider failed; provider output is redacted');
        try{$decoded=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new \RuntimeException('duo effects: provider returned malformed JSON');}
        if(!is_array($decoded)||array_is_list($decoded)||RollbackControl::canonical($decoded)."\n"!==$stdout)throw new \RuntimeException('duo effects: provider returned noncanonical evidence');
        self::rejectSecrets($decoded,'provider response');return $decoded;
    }

    private static function rejectSecrets(mixed $value,string $label,string $key=''): void { if(preg_match('/secret|credential|password|token|signed.?url|authorization/i',$key)===1&&!in_array($key,['credentials_exposed'],true))throw new \RuntimeException("duo effects: $label contains forbidden secret field");if(is_string($value)&&preg_match('#https?://[^\s]*[?&](?:X-Amz-|Signature=|token=)#i',$value)===1)throw new \RuntimeException("duo effects: $label contains a signed URL");if(is_array($value))foreach($value as $k=>$v)self::rejectSecrets($v,$label,(string)$k); }
    private static function artifactPath(string $root,array $m,string $relative): string { if(!preg_match('#^artifacts/[A-Za-z0-9._-]+$#',$relative))throw new \RuntimeException('duo effects: unsafe artifact path');return self::receiptDirectory($root,(string)$m['receipt_id']).'/'.$relative; }
    private static function assertHash(string $v,string $l): void { if(preg_match('/^[0-9a-f]{64}$/',$v)!==1)throw new \RuntimeException("duo effects: $l must be sha256"); }
    private static function assertActor(string $v,string $l): void { if($v===''||strlen($v)>512||preg_match('/[\x00-\x1f\x7f]/',$v)===1)throw new \RuntimeException("duo effects: $l is malformed"); }
    private static function assertIdentifier(string $v,string $l,int $min,int $max): void { if(strlen($v)<$min||strlen($v)>$max||preg_match('/^[A-Za-z0-9._:-]+$/',$v)!==1)throw new \RuntimeException("duo effects: $l is malformed"); }
    /** @param list<string> $expected */ private static function assertExactKeys(array $v,array $expected,string $l): void { $a=array_keys($v);sort($a,SORT_STRING);sort($expected,SORT_STRING);if($a!==$expected)throw new \RuntimeException("duo effects: $l has missing or unknown fields"); }
    private static function timeValue(string $v): int { $t=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new \DateTimeZone('UTC'));if(!$t||$t->format('Y-m-d\TH:i:s\Z')!==$v)throw new \RuntimeException('duo effects: timestamp must be canonical UTC seconds');return $t->getTimestamp(); }
    /** @return array<string,mixed> */ private static function readCanonical(string $p,string $l): array { self::assertAbsoluteRegularFile($p,$l);$raw=file_get_contents($p);try{$v=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new \RuntimeException("duo effects: $l is malformed JSON");}if(!is_array($v)||array_is_list($v)||RollbackControl::canonical($v)."\n"!==$raw)throw new \RuntimeException("duo effects: $l must be canonical JSON");return $v; }
    private static function assertAbsoluteRegularFile(string $p,string $l): void { if($p===''||$p[0]!=='/'||is_link($p)||!is_file($p))throw new \RuntimeException("duo effects: $l must be an absolute regular file"); }
    private static function ensureDirectory(string $p,int $m): void { if(is_link($p)||(!is_dir($p)&&!@mkdir($p,$m,true)&&!is_dir($p)))throw new \RuntimeException("duo effects: unsafe directory '$p'");@chmod($p,$m); }
    private static function syncDirectory(string $p): void { $h=@fopen($p,'r');if(!is_resource($h)||!fsync($h))throw new \RuntimeException('duo effects: could not fsync directory');fclose($h); }
    private static function publishExact(string $p,string $b,int $m,string $l): void { if(is_link($p)||(file_exists($p)&&!is_file($p)))throw new \RuntimeException("duo effects: unsafe $l path");if(is_file($p)){if(file_get_contents($p)!==$b)throw new \RuntimeException("duo effects: immutable $l differs");return;}self::ensureDirectory(dirname($p),0700);$tmp=$p.'.tmp-'.bin2hex(random_bytes(8));$h=@fopen($tmp,'x+b');if(!is_resource($h))throw new \RuntimeException("duo effects: could not create $l");try{chmod($tmp,$m);if(fwrite($h,$b)!==strlen($b)||!fflush($h)||!fsync($h))throw new \RuntimeException("duo effects: could not persist $l");fclose($h);$h=null;if(!rename($tmp,$p))throw new \RuntimeException("duo effects: could not publish $l");self::syncDirectory(dirname($p));}finally{if(is_resource($h))fclose($h);@unlink($tmp);} }
    private static function receiptDirectory(string $root,string $id): string { self::assertIdentifier($id,'receipt id',32,64);return dirname($root).'/rollback/'.$id; }
    /** @template T @param callable():T $callback @return T */ private static function withLock(string $root,callable $callback): mixed { $p=$root.'/effect-bundle.lock';if(is_link($p)||(file_exists($p)&&!is_file($p)))throw new \RuntimeException('duo effects: lock path is unsafe');$h=@fopen($p,'c+b');if(!is_resource($h)||!flock($h,LOCK_EX))throw new \RuntimeException('duo effects: could not acquire lock');try{return $callback();}finally{flock($h,LOCK_UN);fclose($h);} }
}
