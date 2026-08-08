#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Fatal-safe, database-independent authority for a future verified rollback.
 *
 * This runtime intentionally knows nothing about WordPress or any particular
 * resource restorer. It owns only the authorization substrate: one stable
 * target identity, a never-reused generation, an immutable signed receipt,
 * an append-only signed event chain, and a bounded recovery claimant epoch.
 * Resource-specific implementations submit their exact input/result hashes
 * through this interface; they cannot broaden the receipt after `prepared`.
 */
final class RollbackControl {
    public const TARGET_FORMAT = 'duo-rollback-target/v1';
    public const RECEIPT_FORMAT = 'duo-rollback-receipt/v2';
    private const LEGACY_RECEIPT_FORMAT = 'duo-rollback-receipt/v1';
    public const EVENT_FORMAT = 'duo-rollback-event/v1';

    /** @var list<string> */
    private const STATES = [
        'prepared',
        'promoting',
        'verifying_new',
        'committed',
        'rollback_pending',
        'rolling_back',
        'verifying_prior',
        'rolled_back',
    ];

    /** @var list<string> */
    private const TERMINAL_STATES = ['committed', 'rolled_back'];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'prepared' => ['promoting', 'rollback_pending'],
        'promoting' => ['verifying_new', 'rollback_pending'],
        'verifying_new' => ['committed', 'rollback_pending'],
        'rollback_pending' => ['rolling_back'],
        'rolling_back' => ['verifying_prior'],
        'verifying_prior' => ['rolled_back'],
        'committed' => [],
        'rolled_back' => [],
    ];

    /** @var list<string> */
    private const RECEIPT_KEYS = [
        'adapter_versions_sha256',
        'artifact_hash',
        'checkpoint_sha256',
        'claim_ttl_seconds',
        'code_release_metadata_sha256',
        'created_at',
        'encryption_key_id',
        'exclusion_token_sha256',
        'format',
        'generation',
        'ledger_session_sha256',
        'lifecycle_receipts_sha256',
        'owner',
        'prior_code_descriptor_sha256',
        'prior_verifier_inputs_sha256',
        'receipt_id',
        'resources_inventory_sha256',
        'retention_until',
        'runtime_fingerprints_sha256',
        'signing_key_id',
        'target_id',
        'uploads_inventory_sha256',
    ];

    /** @var list<string> */
    private const LEGACY_RECEIPT_KEYS = [
        'adapter_versions_sha256', 'artifact_hash', 'checkpoint_sha256',
        'claim_ttl_seconds', 'created_at', 'encryption_key_id',
        'exclusion_token_sha256', 'format', 'generation',
        'ledger_session_sha256', 'lifecycle_receipts_sha256', 'owner',
        'prior_code_descriptor_sha256', 'prior_verifier_inputs_sha256',
        'receipt_id', 'resources_inventory_sha256', 'retention_until',
        'runtime_fingerprints_sha256', 'signing_key_id', 'target_id',
        'uploads_inventory_sha256',
    ];

    /** @var list<string> */
    private const EVENT_KEYS = [
        'artifact_hash',
        'attempt',
        'claim_epoch',
        'claim_expires_at',
        'claimant',
        'format',
        'generation',
        'input_sha256',
        'operation_id',
        'operation_status',
        'owner',
        'previous_event_sha256',
        'receipt_id',
        'result_sha256',
        'sequence',
        'signing_key_id',
        'state',
        'target_id',
        'timestamp',
    ];

    /**
     * Create the persistent control root once. Re-adoption verifies and
     * preserves an existing target identity and every rollback receipt.
     *
     * @return array<string,mixed>
     */
    public static function initialize(string $root, ?string $targetId = null): array {
        if ($targetId !== null) {
            self::assertIdentifier($targetId, 'target id', 32, 32);
        }
        self::ensureDirectory($root, 0700);
        self::ensureDirectory($root . '/public-keys', 0700);
        self::ensureDirectory(dirname($root) . '/rollback', 0700);
        self::assertRegularOrAbsent($root . '/target.lock', 'target lock');
        $lock = @fopen($root . '/target.lock', 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('duo rollback: could not open target lock');
        }
        @chmod($root . '/target.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('duo rollback: could not acquire target lock');
            }
            $path = $root . '/target.json';
            if (is_file($path)) {
                $target = self::readCanonical($path, 'target record');
                self::validateTarget($target);
                if ($targetId !== null && !hash_equals((string) $target['target_id'], $targetId)) {
                    throw new \RuntimeException('duo rollback: adoption target id does not match the existing control root');
                }
                return $target;
            }
            self::assertRegularOrAbsent($path, 'target record');
            $target = [
                'active_receipt' => null,
                'artifact_hash' => null,
                'claim_epoch' => 0,
                'claim_expires_at' => null,
                'claimant' => null,
                'format' => self::TARGET_FORMAT,
                'generation' => 0,
                'head_event_sha256' => null,
                'owner' => null,
                'sequence' => 0,
                'state' => null,
                'target_id' => $targetId ?? bin2hex(random_bytes(16)),
                'updated_at' => self::timestamp(),
            ];
            self::atomicWrite($path, self::canonical($target) . "\n", 0600, 'target');
            return $target;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Install one immutable public verification key; private keys never enter
     * this runtime. Rotation uses a new key id so historical signatures keep
     * their original verification material forever.
     */
    public static function installPublicKey(string $root, string $keyId, string $publicKeyBase64): void {
        self::assertKeyId($keyId);
        $bytes = base64_decode(trim($publicKeyBase64), true);
        if (!is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || trim($publicKeyBase64) !== base64_encode($bytes)) {
            throw new \RuntimeException('duo rollback: public key must be canonical base64 Ed25519 bytes');
        }
        $canonical = base64_encode($bytes) . "\n";
        self::withLock($root, function () use ($root, $keyId, $canonical): array {
            self::ensureDirectory($root . '/public-keys', 0700);
            self::publishExactOrVerify(
                $root . '/public-keys/' . $keyId . '.pub',
                $canonical,
                0644,
                'public-key'
            );
            return ['ok' => true];
        });
    }

    /**
     * Apply a controller-signed request uploaded outside the command line.
     *
     * @return array<string,mixed>
     */
    public static function handleRequest(string $root, string $requestPath): array {
        self::assertRegularFile($requestPath, 'request');
        $request = self::readCanonical($requestPath, 'request');
        self::assertExactKeys($request, ['action', 'event', 'receipt'], 'request');
        $action = (string) ($request['action'] ?? '');
        if (!in_array($action, ['claim', 'append'], true)) {
            throw new \RuntimeException("duo rollback: unsupported request action '$action'");
        }
        if (!is_array($request['event'] ?? null)) {
            throw new \RuntimeException('duo rollback: request event must be a signed object');
        }
        if ($action === 'claim' && !is_array($request['receipt'] ?? null)) {
            throw new \RuntimeException('duo rollback: claim request needs a signed receipt');
        }
        if ($action === 'append' && $request['receipt'] !== null) {
            throw new \RuntimeException('duo rollback: append request must not replace the immutable receipt');
        }

        return self::withLock($root, function () use ($root, $request, $action): array {
            $target = self::readTarget($root);
            if ($action === 'claim') {
                return self::claim($root, $target, (array) $request['receipt'], (array) $request['event']);
            }
            return self::append($root, $target, (array) $request['event']);
        });
    }

    /**
     * Verify the complete active receipt/event chain and return bounded status.
     * A malformed or tampered active generation throws, making host status
     * non-green instead of trusting target.json alone.
     *
     * @return array<string,mixed>
     */
    public static function status(string $root): array {
        return self::withLock($root, function () use ($root): array {
            $target = self::readTarget($root);
            if ($target['active_receipt'] === null) {
                return [
                    'active' => false,
                    'format' => self::TARGET_FORMAT,
                    'generation' => (int) $target['generation'],
                    'ok' => true,
                    'target_id' => (string) $target['target_id'],
                ];
            }
            $verified = self::verifyActive($root, $target);
            $receipt = $verified['receipt'];
            return [
                'active' => true,
                'artifact_hash' => (string) $receipt['artifact_hash'],
                'claim_epoch' => (int) $target['claim_epoch'],
                'claim_expires_at' => (string) $target['claim_expires_at'],
                'claim_ttl_seconds' => (int) $receipt['claim_ttl_seconds'],
                'claimant' => (string) $target['claimant'],
                'checkpoint_sha256' => (string) $receipt['checkpoint_sha256'],
                'code_release_metadata_sha256' => isset($receipt['code_release_metadata_sha256'])
                    ? (string) $receipt['code_release_metadata_sha256']
                    : null,
                'encryption_key_id' => (string) $receipt['encryption_key_id'],
                'exclusion_token_sha256' => (string) $receipt['exclusion_token_sha256'],
                'lifecycle_receipts_sha256' => (string) $receipt['lifecycle_receipts_sha256'],
                'format' => self::TARGET_FORMAT,
                'generation' => (int) $target['generation'],
                'head_event_sha256' => (string) $target['head_event_sha256'],
                'ok' => true,
                'open_operations' => count($verified['open_operations']),
                'owner' => (string) $receipt['owner'],
                'prior_code_descriptor_sha256' => (string) $receipt['prior_code_descriptor_sha256'],
                'receipt_id' => (string) $receipt['receipt_id'],
                'retention_until' => (string) $receipt['retention_until'],
                'uploads_inventory_sha256' => (string) $receipt['uploads_inventory_sha256'],
                'sequence' => (int) $target['sequence'],
                'state' => (string) $target['state'],
                'target_id' => (string) $target['target_id'],
                'terminal' => in_array((string) $target['state'], self::TERMINAL_STATES, true),
            ];
        });
    }

    /** Verify a controller signature using only the adopted public key. */
    public static function verifyEnvelope(string $root, array $signed, string $label): array {
        return self::verifySigned($root, $signed, $label);
    }

    /**
     * Return the verified receipt and open-operation set used by the isolated
     * recovery executor. Nothing in this view is sourced from WordPress.
     *
     * @return array{receipt:array<string,mixed>,status:array<string,mixed>,open_operations:array<string,array<string,mixed>>}
     */
    public static function activeEvidence(string $root): array {
        return self::withLock($root, function () use ($root): array {
            $target = self::readTarget($root);
            if ($target['active_receipt'] === null) {
                throw new \RuntimeException('duo rollback: no active receipt exists');
            }
            $verified = self::verifyActive($root, $target);
            return [
                'open_operations' => $verified['open_operations'],
                'receipt' => $verified['receipt'],
                'status' => self::statusFromVerified($target, $verified),
            ];
        });
    }

    /**
     * Return hash-only audit evidence for the complete signed active chain.
     * This is the supported export used by external certification; it never
     * exposes provider tokens, checkpoint bytes, or signing material.
     *
     * @return array<string,mixed>
     */
    public static function auditEvidence(string $root): array {
        return self::withLock($root, function () use ($root): array {
            $target = self::readTarget($root);
            if ($target['active_receipt'] === null) {
                throw new \RuntimeException('duo rollback: no active receipt exists');
            }
            self::verifyActive($root, $target);
            $receiptId = (string) $target['active_receipt'];
            $directory = self::receiptDirectory($root, $receiptId);
            $receiptPath = $directory . '/receipt.json';
            $receiptHash = hash_file('sha256', $receiptPath);
            $targetHash = hash_file('sha256', $root . '/target.json');
            if (!is_string($receiptHash) || !is_string($targetHash)) {
                throw new \RuntimeException('duo rollback: could not hash active audit evidence');
            }
            $eventFiles = glob($directory . '/events/*.json') ?: [];
            sort($eventFiles, SORT_STRING);
            $events = [];
            foreach ($eventFiles as $index => $path) {
                $hash = hash_file('sha256', $path);
                if (!is_string($hash)) {
                    throw new \RuntimeException('duo rollback: could not hash active event evidence');
                }
                $events[] = ['sequence' => $index + 1, 'sha256' => $hash];
            }
            return [
                'event_chain_sha256' => hash('sha256', self::canonical($events) . "\n"),
                'events' => $events,
                'format' => 'duo-rollback-audit/v1',
                'generation' => (int) $target['generation'],
                'ok' => true,
                'receipt_id' => $receiptId,
                'receipt_sha256' => $receiptHash,
                'state' => (string) $target['state'],
                'target_id' => (string) $target['target_id'],
                'target_record_sha256' => $targetHash,
            ];
        });
    }

    /** @return array<string,mixed> */
    private static function claim(string $root, array $target, array $signedReceipt, array $signedEvent): array {
        $receipt = self::verifySigned($root, $signedReceipt, 'receipt');
        self::validateReceipt($receipt, (string) ($signedReceipt['key_id'] ?? ''));
        $event = self::verifySigned($root, $signedEvent, 'event');
        self::validateEvent($event, (string) ($signedEvent['key_id'] ?? ''));

        $eventHash = hash('sha256', self::canonical($signedEvent));
        if ($target['active_receipt'] !== null
            && hash_equals((string) $target['active_receipt'], (string) $receipt['receipt_id'])
            && (int) $target['generation'] === (int) $receipt['generation']
            && (int) $target['sequence'] === 1
            && hash_equals((string) $target['head_event_sha256'], $eventHash)) {
            self::verifyActive($root, $target);
            return self::statusUnlocked($root, $target);
        }

        if ($target['active_receipt'] !== null
            && !in_array((string) $target['state'], self::TERMINAL_STATES, true)) {
            throw new \RuntimeException(
                "duo rollback: target generation {$target['generation']} is still {$target['state']}"
            );
        }
        $expectedGeneration = (int) $target['generation'] + 1;
        if ((string) $receipt['target_id'] !== (string) $target['target_id']
            || (int) $receipt['generation'] !== $expectedGeneration) {
            throw new \RuntimeException('duo rollback: receipt does not claim the exact next target generation');
        }
        self::assertEventReceiptMatch($event, $receipt);
        if ((int) $event['sequence'] !== 1
            || (string) $event['previous_event_sha256'] !== str_repeat('0', 64)
            || (string) $event['state'] !== 'prepared'
            || (string) $event['operation_status'] !== 'state_transition'
            || (int) $event['claim_epoch'] !== 1) {
            throw new \RuntimeException('duo rollback: first event must establish prepared at sequence/claim epoch 1');
        }
        self::assertClaimExpiry($event, (int) $receipt['claim_ttl_seconds']);
        if (self::timeValue((string) $event['timestamp']) < self::timeValue((string) $receipt['created_at'])) {
            throw new \RuntimeException('duo rollback: first event predates its immutable receipt');
        }
        if (RecoveryExecutor::configured($root)) {
            RecoveryExecutor::assertClaimExclusion($root, $receipt, $event);
            if (CheckpointBundle::configured($root)) {
                CheckpointBundle::assertClaimCheckpoint($root, $receipt, $event);
            }
            if (CodeRelease::configured($root)) {
                CodeRelease::assertClaimCodeRelease($root, $receipt, $event);
            }
            if (UploadBundle::configured($root)) {
                UploadBundle::assertClaimUploadBundle($root, $receipt, $event);
            }
            if (EffectBundle::configured($root)) {
                EffectBundle::assertClaimEffectBundle($root, $receipt, $event);
            }
        }

        $receiptId = (string) $receipt['receipt_id'];
        $dir = self::receiptDirectory($root, $receiptId);
        self::ensureDirectory($dir, 0700);
        self::ensureDirectory($dir . '/events', 0700);
        $receiptBytes = self::canonical($signedReceipt) . "\n";
        self::publishExactOrVerify($dir . '/receipt.json', $receiptBytes, 0600, 'receipt');
        self::crashPoint('claim:after-receipt');

        $eventPath = self::eventPath($dir, 1, $eventHash);
        self::publishExactOrVerify($eventPath, self::canonical($signedEvent) . "\n", 0600, 'event');
        self::crashPoint('claim:after-event');

        $next = self::targetFromEvent($target, $receipt, $event, $eventHash);
        self::atomicWrite($root . '/target.json', self::canonical($next) . "\n", 0600, 'target');
        self::crashPoint('claim:after-target');
        return self::statusUnlocked($root, $next);
    }

    /** @return array<string,mixed> */
    private static function append(string $root, array $target, array $signedEvent): array {
        if ($target['active_receipt'] === null) {
            throw new \RuntimeException('duo rollback: no active receipt exists');
        }
        $verified = self::verifyActive($root, $target);
        $receipt = $verified['receipt'];
        $event = self::verifySigned($root, $signedEvent, 'event');
        self::validateEvent($event, (string) ($signedEvent['key_id'] ?? ''));
        self::assertEventReceiptMatch($event, $receipt);
        $eventHash = hash('sha256', self::canonical($signedEvent));
        if ((int) $event['sequence'] === (int) $target['sequence']
            && hash_equals((string) $target['head_event_sha256'], $eventHash)) {
            return self::statusUnlocked($root, $target);
        }
        if (in_array((string) $target['state'], self::TERMINAL_STATES, true)) {
            throw new \RuntimeException("duo rollback: terminal generation {$target['generation']} cannot be mutated");
        }
        if ((int) $event['sequence'] !== (int) $target['sequence'] + 1
            || !hash_equals((string) $target['head_event_sha256'], (string) $event['previous_event_sha256'])) {
            throw new \RuntimeException('duo rollback: event does not continue the exact active hash-chain head');
        }
        if (self::timeValue((string) $event['timestamp']) < $verified['last_timestamp']) {
            throw new \RuntimeException('duo rollback: event timestamp moved backwards');
        }

        $isTakeover = (string) $event['operation_status'] === 'takeover';
        if ($isTakeover) {
            if (self::timeValue((string) $event['timestamp']) <= self::timeValue((string) $target['claim_expires_at'])) {
                throw new \RuntimeException('duo rollback: recovery claim has not expired; operator takeover refused');
            }
            if ((int) $event['claim_epoch'] !== (int) $target['claim_epoch'] + 1) {
                throw new \RuntimeException('duo rollback: takeover must advance the exact claim epoch');
            }
            if ((string) $event['state'] !== (string) $target['state']) {
                throw new \RuntimeException('duo rollback: takeover cannot change rollback state');
            }
        } else {
            if (!hash_equals((string) $target['claimant'], (string) $event['claimant'])
                || (int) $event['claim_epoch'] !== (int) $target['claim_epoch']) {
                throw new \RuntimeException('duo rollback: stale or foreign recovery claimant is fenced');
            }
        }
        self::assertClaimExpiry($event, (int) $receipt['claim_ttl_seconds']);
        self::validateNextEvent($event, (string) $target['state'], $verified['open_operations'], $isTakeover);

        $dir = self::receiptDirectory($root, (string) $receipt['receipt_id']);
        $eventPath = self::eventPath($dir, (int) $event['sequence'], $eventHash);
        self::publishExactOrVerify($eventPath, self::canonical($signedEvent) . "\n", 0600, 'event');
        self::crashPoint('append:after-event');

        $next = self::targetFromEvent($target, $receipt, $event, $eventHash);
        self::atomicWrite($root . '/target.json', self::canonical($next) . "\n", 0600, 'target');
        self::crashPoint('append:after-target');
        return self::statusUnlocked($root, $next);
    }

    /** @return array{receipt:array<string,mixed>,open_operations:array<string,array<string,mixed>>,last_timestamp:int} */
    private static function verifyActive(string $root, array $target): array {
        $receiptId = (string) $target['active_receipt'];
        $dir = self::receiptDirectory($root, $receiptId);
        $signedReceipt = self::readCanonical($dir . '/receipt.json', 'receipt');
        $receipt = self::verifySigned($root, $signedReceipt, 'receipt');
        self::validateReceipt($receipt, (string) ($signedReceipt['key_id'] ?? ''));
        if ($receiptId !== (string) $receipt['receipt_id']
            || (string) $target['target_id'] !== (string) $receipt['target_id']
            || (int) $target['generation'] !== (int) $receipt['generation']
            || (string) $target['owner'] !== (string) $receipt['owner']
            || (string) $target['artifact_hash'] !== (string) $receipt['artifact_hash']) {
            throw new \RuntimeException('duo rollback: target record does not match its immutable receipt');
        }

        $files = glob($dir . '/events/*.json') ?: [];
        sort($files, SORT_STRING);
        $expectedCount = (int) $target['sequence'];
        if (count($files) < $expectedCount || count($files) > $expectedCount + 1) {
            throw new \RuntimeException('duo rollback: event directory does not match the durable sequence');
        }
        $previous = str_repeat('0', 64);
        $state = null;
        $claimant = null;
        $claimEpoch = 0;
        $claimExpires = null;
        $lastTimestamp = 0;
        $open = [];
        foreach ($files as $index => $path) {
            $sequence = $index + 1;
            $signedEvent = self::readCanonical($path, "event $sequence");
            $hash = hash('sha256', self::canonical($signedEvent));
            $expectedName = sprintf('%012d-%s.json', $sequence, $hash);
            if (basename($path) !== $expectedName) {
                throw new \RuntimeException("duo rollback: event $sequence filename/hash mismatch");
            }
            $event = self::verifySigned($root, $signedEvent, "event $sequence");
            self::validateEvent($event, (string) ($signedEvent['key_id'] ?? ''));
            self::assertEventReceiptMatch($event, $receipt);
            if ((int) $event['sequence'] !== $sequence
                || !hash_equals($previous, (string) $event['previous_event_sha256'])) {
                throw new \RuntimeException("duo rollback: event $sequence breaks the hash chain");
            }
            if (self::timeValue((string) $event['timestamp']) < $lastTimestamp) {
                throw new \RuntimeException("duo rollback: event $sequence timestamp moved backwards");
            }
            if ($sequence === 1) {
                if ((string) $event['state'] !== 'prepared'
                    || (string) $event['operation_status'] !== 'state_transition'
                    || (int) $event['claim_epoch'] !== 1) {
                    throw new \RuntimeException('duo rollback: first event is not the prepared authority boundary');
                }
            } else {
                $takeover = (string) $event['operation_status'] === 'takeover';
                if ($takeover) {
                    if ((int) $event['claim_epoch'] !== $claimEpoch + 1
                        || (string) $event['state'] !== $state
                        || self::timeValue((string) $event['timestamp']) <= self::timeValue((string) $claimExpires)) {
                        throw new \RuntimeException("duo rollback: event $sequence is an invalid claimant takeover");
                    }
                } elseif ((int) $event['claim_epoch'] !== $claimEpoch
                    || (string) $event['claimant'] !== $claimant) {
                    throw new \RuntimeException("duo rollback: event $sequence was written by a fenced claimant");
                }
                self::validateNextEvent($event, (string) $state, $open, $takeover);
            }
            self::assertClaimExpiry($event, (int) $receipt['claim_ttl_seconds']);
            self::applyOperation($event, $open);
            $previous = $hash;
            $state = (string) $event['state'];
            $claimant = (string) $event['claimant'];
            $claimEpoch = (int) $event['claim_epoch'];
            $claimExpires = (string) $event['claim_expires_at'];
            $lastTimestamp = self::timeValue((string) $event['timestamp']);

            if ($sequence === $expectedCount) {
                if (!hash_equals((string) $target['head_event_sha256'], $hash)
                    || (string) $target['state'] !== $state
                    || (string) $target['claimant'] !== $claimant
                    || (int) $target['claim_epoch'] !== $claimEpoch
                    || (string) $target['claim_expires_at'] !== $claimExpires) {
                    throw new \RuntimeException('duo rollback: target record does not match its committed event head');
                }
            }
        }
        if ($expectedCount < 1) {
            throw new \RuntimeException('duo rollback: active receipt has no committed event');
        }
        // One exact next event can remain after a crash between event publish
        // and target publication. It is not silently accepted; retrying that
        // same signed event completes the compare-and-swap.
        if (count($files) === $expectedCount + 1) {
            // The loop already proved this trailing event. Reconstruct the
            // committed view only, because target.json remains authoritative
            // until an exact request retries the publish.
            $committed = self::verifyCommittedPrefix($root, $target, $receipt, $files, $expectedCount);
            return $committed + ['receipt' => $receipt];
        }
        return ['receipt' => $receipt, 'open_operations' => $open, 'last_timestamp' => $lastTimestamp];
    }

    /**
     * Re-read only the target-committed prefix when an exact orphan next event
     * exists after a crash. This prevents status from reporting uncommitted
     * state while still preserving that event for idempotent retry.
     *
     * @param list<string> $files
     * @return array{open_operations:array<string,array<string,mixed>>,last_timestamp:int}
     */
    private static function verifyCommittedPrefix(string $root, array $target, array $receipt, array $files, int $count): array {
        $open = [];
        $last = 0;
        foreach (array_slice($files, 0, $count) as $path) {
            $signed = self::readCanonical($path, 'committed event');
            $event = self::verifySigned($root, $signed, 'committed event');
            self::assertEventReceiptMatch($event, $receipt);
            self::applyOperation($event, $open);
            $last = self::timeValue((string) $event['timestamp']);
        }
        return ['open_operations' => $open, 'last_timestamp' => $last];
    }

    /** @param array<string,array<string,mixed>> $open */
    private static function validateNextEvent(array $event, string $currentState, array $open, bool $takeover): void {
        $status = (string) $event['operation_status'];
        $nextState = (string) $event['state'];
        if ($takeover) {
            return;
        }
        if ($status === 'state_transition') {
            if (!in_array($nextState, self::TRANSITIONS[$currentState] ?? [], true)) {
                throw new \RuntimeException("duo rollback: invalid state transition $currentState -> $nextState");
            }
            if ($open) {
                throw new \RuntimeException('duo rollback: state transition refused with incomplete resource operations');
            }
            return;
        }
        if ($nextState !== $currentState) {
            throw new \RuntimeException('duo rollback: resource operation cannot change rollback state');
        }
        $key = self::operationKey($event);
        if ($status === 'prepared' && isset($open[$key])) {
            throw new \RuntimeException("duo rollback: operation '$key' is already prepared");
        }
        if ($status === 'completed') {
            $prepared = $open[$key] ?? null;
            if (!is_array($prepared)
                || !hash_equals((string) $prepared['input_sha256'], (string) $event['input_sha256'])) {
                throw new \RuntimeException("duo rollback: completion for '$key' has no exact prepared operation");
            }
        }
    }

    /** @param array<string,array<string,mixed>> &$open */
    private static function applyOperation(array $event, array &$open): void {
        $status = (string) $event['operation_status'];
        if (!in_array($status, ['prepared', 'completed'], true)) {
            return;
        }
        $key = self::operationKey($event);
        if ($status === 'prepared') {
            $open[$key] = $event;
        } else {
            unset($open[$key]);
        }
    }

    private static function operationKey(array $event): string {
        return (string) $event['operation_id'] . '#' . (int) $event['attempt'];
    }

    private static function assertEventReceiptMatch(array $event, array $receipt): void {
        foreach (['receipt_id', 'target_id', 'generation', 'owner', 'artifact_hash'] as $key) {
            if ((string) $event[$key] !== (string) $receipt[$key]) {
                throw new \RuntimeException("duo rollback: event $key does not match the immutable receipt");
            }
        }
    }

    private static function assertClaimExpiry(array $event, int $ttl): void {
        $expected = self::formatTime(self::timeValue((string) $event['timestamp']) + $ttl);
        if ((string) $event['claim_expires_at'] !== $expected) {
            throw new \RuntimeException('duo rollback: event claim expiry does not match the receipt TTL');
        }
    }

    /** @return array<string,mixed> */
    private static function targetFromEvent(array $_prior, array $receipt, array $event, string $eventHash): array {
        return [
            'active_receipt' => (string) $receipt['receipt_id'],
            'artifact_hash' => (string) $receipt['artifact_hash'],
            'claim_epoch' => (int) $event['claim_epoch'],
            'claim_expires_at' => (string) $event['claim_expires_at'],
            'claimant' => (string) $event['claimant'],
            'format' => self::TARGET_FORMAT,
            'generation' => (int) $receipt['generation'],
            'head_event_sha256' => $eventHash,
            'owner' => (string) $receipt['owner'],
            'sequence' => (int) $event['sequence'],
            'state' => (string) $event['state'],
            'target_id' => (string) $receipt['target_id'],
            'updated_at' => (string) $event['timestamp'],
        ];
    }

    /** @return array<string,mixed> */
    private static function statusUnlocked(string $root, array $target): array {
        $verified = self::verifyActive($root, $target);
        return self::statusFromVerified($target, $verified);
    }

    /** @param array{receipt:array<string,mixed>,open_operations:array<string,array<string,mixed>>,last_timestamp:int} $verified */
    private static function statusFromVerified(array $target, array $verified): array {
        return [
            'active' => true,
            'artifact_hash' => (string) $target['artifact_hash'],
            'claim_epoch' => (int) $target['claim_epoch'],
            'claim_expires_at' => (string) $target['claim_expires_at'],
            'claim_ttl_seconds' => (int) $verified['receipt']['claim_ttl_seconds'],
            'claimant' => (string) $target['claimant'],
            'checkpoint_sha256' => (string) $verified['receipt']['checkpoint_sha256'],
            'code_release_metadata_sha256' => isset($verified['receipt']['code_release_metadata_sha256'])
                ? (string) $verified['receipt']['code_release_metadata_sha256']
                : null,
            'encryption_key_id' => (string) $verified['receipt']['encryption_key_id'],
            'exclusion_token_sha256' => (string) $verified['receipt']['exclusion_token_sha256'],
            'lifecycle_receipts_sha256' => (string) $verified['receipt']['lifecycle_receipts_sha256'],
            'format' => self::TARGET_FORMAT,
            'generation' => (int) $target['generation'],
            'head_event_sha256' => (string) $target['head_event_sha256'],
            'ok' => true,
            'open_operations' => count($verified['open_operations']),
            'owner' => (string) $target['owner'],
            'prior_code_descriptor_sha256' => (string) $verified['receipt']['prior_code_descriptor_sha256'],
            'receipt_id' => (string) $target['active_receipt'],
            'retention_until' => (string) $verified['receipt']['retention_until'],
            'uploads_inventory_sha256' => (string) $verified['receipt']['uploads_inventory_sha256'],
            'sequence' => (int) $target['sequence'],
            'state' => (string) $target['state'],
            'target_id' => (string) $target['target_id'],
            'terminal' => in_array((string) $target['state'], self::TERMINAL_STATES, true),
        ];
    }

    /** @return array<string,mixed> */
    private static function verifySigned(string $root, array $signed, string $label): array {
        self::assertExactKeys($signed, ['key_id', 'payload', 'signature'], "signed $label");
        $keyId = (string) ($signed['key_id'] ?? '');
        self::assertKeyId($keyId);
        if (!is_array($signed['payload'] ?? null)) {
            throw new \RuntimeException("duo rollback: signed $label payload must be an object");
        }
        $signature = base64_decode((string) ($signed['signature'] ?? ''), true);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException("duo rollback: signed $label has malformed signature bytes");
        }
        $keyPath = $root . '/public-keys/' . $keyId . '.pub';
        self::assertRegularFile($keyPath, "public key '$keyId'");
        $keyRaw = file_get_contents($keyPath);
        $key = is_string($keyRaw) ? base64_decode(trim($keyRaw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $keyRaw !== base64_encode($key) . "\n") {
            throw new \RuntimeException("duo rollback: installed public key '$keyId' is malformed");
        }
        $payload = (array) $signed['payload'];
        if (!sodium_crypto_sign_verify_detached($signature, self::canonical($payload), $key)) {
            throw new \RuntimeException("duo rollback: signed $label signature verification failed");
        }
        return $payload;
    }

    private static function validateReceipt(array $receipt, string $envelopeKeyId): void {
        $format = (string) ($receipt['format'] ?? '');
        if ($format === self::RECEIPT_FORMAT) {
            self::assertExactKeys($receipt, self::RECEIPT_KEYS, 'receipt payload');
        } elseif ($format === self::LEGACY_RECEIPT_FORMAT) {
            self::assertExactKeys($receipt, self::LEGACY_RECEIPT_KEYS, 'receipt payload');
        } else {
            throw new \RuntimeException('duo rollback: unsupported receipt format');
        }
        self::assertIdentifier((string) $receipt['receipt_id'], 'receipt id', 32, 64);
        self::assertIdentifier((string) $receipt['target_id'], 'target id', 32, 32);
        self::assertActor((string) $receipt['owner'], 'promotion owner');
        self::assertKeyId((string) $receipt['signing_key_id']);
        if (!hash_equals((string) $receipt['signing_key_id'], $envelopeKeyId)) {
            throw new \RuntimeException('duo rollback: receipt signing_key_id does not match its signature envelope');
        }
        if (!is_int($receipt['generation']) || $receipt['generation'] < 1) {
            throw new \RuntimeException('duo rollback: receipt generation must be a positive integer');
        }
        if (!is_int($receipt['claim_ttl_seconds'])
            || $receipt['claim_ttl_seconds'] < 30
            || $receipt['claim_ttl_seconds'] > 3600) {
            throw new \RuntimeException('duo rollback: receipt claim TTL must be 30..3600 seconds');
        }
        foreach ([
            'artifact_hash',
            'checkpoint_sha256',
            ...($format === self::RECEIPT_FORMAT ? ['code_release_metadata_sha256'] : []),
            'prior_code_descriptor_sha256',
            'lifecycle_receipts_sha256',
            'uploads_inventory_sha256',
            'resources_inventory_sha256',
            'adapter_versions_sha256',
            'prior_verifier_inputs_sha256',
            'ledger_session_sha256',
            'runtime_fingerprints_sha256',
            'exclusion_token_sha256',
        ] as $key) {
            self::assertHash((string) $receipt[$key], "receipt $key");
        }
        self::assertActor((string) $receipt['encryption_key_id'], 'encryption key id');
        $created = self::timeValue((string) $receipt['created_at']);
        $retention = self::timeValue((string) $receipt['retention_until']);
        if ($retention <= $created) {
            throw new \RuntimeException('duo rollback: receipt retention must end after creation');
        }
    }

    private static function validateEvent(array $event, string $envelopeKeyId): void {
        self::assertExactKeys($event, self::EVENT_KEYS, 'event payload');
        if (($event['format'] ?? '') !== self::EVENT_FORMAT) {
            throw new \RuntimeException('duo rollback: unsupported event format');
        }
        self::assertIdentifier((string) $event['receipt_id'], 'event receipt id', 32, 64);
        self::assertIdentifier((string) $event['target_id'], 'event target id', 32, 32);
        self::assertActor((string) $event['owner'], 'event owner');
        self::assertActor((string) $event['claimant'], 'event claimant');
        self::assertActor((string) $event['operation_id'], 'event operation id');
        self::assertKeyId((string) $event['signing_key_id']);
        if (!hash_equals((string) $event['signing_key_id'], $envelopeKeyId)) {
            throw new \RuntimeException('duo rollback: event signing_key_id does not match its signature envelope');
        }
        if (!is_int($event['generation']) || $event['generation'] < 1
            || !is_int($event['sequence']) || $event['sequence'] < 1
            || !is_int($event['attempt']) || $event['attempt'] < 1
            || !is_int($event['claim_epoch']) || $event['claim_epoch'] < 1) {
            throw new \RuntimeException('duo rollback: event generation/sequence/attempt/claim_epoch must be positive integers');
        }
        if (!in_array((string) $event['state'], self::STATES, true)) {
            throw new \RuntimeException('duo rollback: event has unsupported state');
        }
        if (!in_array((string) $event['operation_status'], ['state_transition', 'prepared', 'completed', 'takeover'], true)) {
            throw new \RuntimeException('duo rollback: event has unsupported operation_status');
        }
        foreach (['artifact_hash', 'previous_event_sha256', 'input_sha256', 'result_sha256'] as $key) {
            self::assertHash((string) $event[$key], "event $key");
        }
        self::timeValue((string) $event['timestamp']);
        self::timeValue((string) $event['claim_expires_at']);
    }

    private static function validateTarget(array $target): void {
        self::assertExactKeys($target, [
            'active_receipt', 'artifact_hash', 'claim_epoch', 'claim_expires_at',
            'claimant', 'format', 'generation', 'head_event_sha256', 'owner',
            'sequence', 'state', 'target_id', 'updated_at',
        ], 'target record');
        if (($target['format'] ?? '') !== self::TARGET_FORMAT) {
            throw new \RuntimeException('duo rollback: unsupported target record format');
        }
        self::assertIdentifier((string) $target['target_id'], 'target id', 32, 32);
        if (!is_int($target['generation']) || $target['generation'] < 0
            || !is_int($target['sequence']) || $target['sequence'] < 0
            || !is_int($target['claim_epoch']) || $target['claim_epoch'] < 0) {
            throw new \RuntimeException('duo rollback: target counters are malformed');
        }
        self::timeValue((string) $target['updated_at']);
        if ($target['active_receipt'] === null) {
            foreach (['artifact_hash', 'claim_expires_at', 'claimant', 'head_event_sha256', 'owner', 'state'] as $key) {
                if ($target[$key] !== null) {
                    throw new \RuntimeException("duo rollback: inactive target retains $key");
                }
            }
            if ($target['sequence'] !== 0 || $target['claim_epoch'] !== 0) {
                throw new \RuntimeException('duo rollback: inactive target retains receipt counters');
            }
            return;
        }
        self::assertIdentifier((string) $target['active_receipt'], 'active receipt id', 32, 64);
        self::assertHash((string) $target['artifact_hash'], 'target artifact hash');
        self::assertHash((string) $target['head_event_sha256'], 'target event head');
        self::assertActor((string) $target['owner'], 'target owner');
        self::assertActor((string) $target['claimant'], 'target claimant');
        self::timeValue((string) $target['claim_expires_at']);
        if (!in_array((string) $target['state'], self::STATES, true)
            || $target['generation'] < 1 || $target['sequence'] < 1 || $target['claim_epoch'] < 1) {
            throw new \RuntimeException('duo rollback: active target state/counters are malformed');
        }
    }

    /** @return array<string,mixed> */
    private static function readTarget(string $root): array {
        $target = self::readCanonical($root . '/target.json', 'target record');
        self::validateTarget($target);
        return $target;
    }

    /** @return array<string,mixed> */
    private static function readCanonical(string $path, string $label): array {
        self::assertRegularFile($path, $label);
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException("duo rollback: could not read $label");
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("duo rollback: $label is invalid JSON: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded) || self::canonical($decoded) . "\n" !== $raw) {
            throw new \RuntimeException("duo rollback: $label is not canonical JSON");
        }
        return $decoded;
    }

    /** @return mixed */
    private static function canonicalize($value) {
        if (!is_array($value)) {
            if (is_float($value) || is_resource($value) || is_object($value)) {
                throw new \RuntimeException('duo rollback: canonical JSON contains unsupported value');
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('duo rollback: canonical JSON object keys must be strings');
            }
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    public static function canonical(array $value): string {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /** @return array{key_id:string,payload:array<string,mixed>,signature:string} */
    public static function sign(array $payload, string $keyId, string $secretKey): array {
        self::assertKeyId($keyId);
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('duo rollback: signing secret has the wrong Ed25519 byte length');
        }
        return [
            'key_id' => $keyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(self::canonical($payload), $secretKey)),
        ];
    }

    /** @param callable():array<string,mixed> $callback @return array<string,mixed> */
    private static function withLock(string $root, callable $callback): array {
        self::ensureDirectory($root, 0700);
        self::assertRegularOrAbsent($root . '/target.lock', 'target lock');
        $lock = @fopen($root . '/target.lock', 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('duo rollback: could not open target lock');
        }
        @chmod($root . '/target.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('duo rollback: could not acquire target lock');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function publishExactOrVerify(string $path, string $bytes, int $mode, string $label): void {
        if (file_exists($path) || is_link($path)) {
            self::assertRegularFile($path, $label);
            $existing = file_get_contents($path);
            if (!is_string($existing) || !hash_equals(hash('sha256', $bytes), hash('sha256', $existing))) {
                throw new \RuntimeException("duo rollback: existing $label does not match idempotent retry bytes");
            }
            return;
        }
        self::atomicWrite($path, $bytes, $mode, $label);
    }

    private static function atomicWrite(string $path, string $bytes, int $mode, string $label): void {
        $dir = dirname($path);
        self::ensureDirectory($dir, 0700);
        self::assertRegularOrAbsent($path, $label);
        $tmp = $dir . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        self::crashPoint("$label:before-write");
        $handle = @fopen($tmp, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException("duo rollback: could not create temporary $label");
        }
        try {
            @chmod($tmp, $mode);
            $written = fwrite($handle, $bytes);
            if ($written !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException("duo rollback: could not durably write temporary $label");
            }
            self::crashPoint("$label:after-file-fsync");
        } finally {
            fclose($handle);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("duo rollback: could not atomically publish $label");
        }
        @chmod($path, $mode);
        self::crashPoint("$label:after-rename");
        self::fsyncDirectory($dir, $label);
        self::crashPoint("$label:after-dir-fsync");
        $actual = file_get_contents($path);
        if (!is_string($actual) || !hash_equals(hash('sha256', $bytes), hash('sha256', $actual))) {
            throw new \RuntimeException("duo rollback: $label readback did not match published bytes");
        }
    }

    private static function fsyncDirectory(string $dir, string $label): void {
        $handle = @fopen($dir, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException("duo rollback: could not open $label parent directory for fsync");
        }
        try {
            if (!fsync($handle)) {
                throw new \RuntimeException("duo rollback: could not fsync $label parent directory");
            }
        } finally {
            fclose($handle);
        }
    }

    private static function crashPoint(string $name): void {
        if (getenv('DUO_ROLLBACK_CRASH_AT') === $name) {
            exit(97);
        }
    }

    private static function ensureDirectory(string $path, int $mode): void {
        if (is_link($path)) {
            throw new \RuntimeException("duo rollback: refusing symlink directory '$path'");
        }
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("duo rollback: could not create directory '$path'");
        }
        if (is_link($path) || !is_dir($path)) {
            throw new \RuntimeException("duo rollback: unsafe directory '$path'");
        }
        @chmod($path, $mode);
    }

    private static function assertRegularFile(string $path, string $label): void {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo rollback: $label is missing or not a regular file");
        }
    }

    private static function assertRegularOrAbsent(string $path, string $label): void {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException("duo rollback: $label path is not a regular file");
        }
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo rollback: $label has missing or unknown fields");
        }
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new \RuntimeException("duo rollback: $label must be a sha256 hex digest");
        }
    }

    private static function assertIdentifier(string $value, string $label, int $min, int $max): void {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/^[0-9a-f]+$/', $value) !== 1) {
            throw new \RuntimeException("duo rollback: $label is malformed");
        }
    }

    private static function assertActor(string $value, string $label): void {
        if (strlen($value) < 1 || strlen($value) > 200
            || preg_match('/^[A-Za-z0-9._:@+\/-]+$/', $value) !== 1) {
            throw new \RuntimeException("duo rollback: $label is malformed");
        }
    }

    private static function assertKeyId(string $value): void {
        if (strlen($value) < 1 || strlen($value) > 64
            || preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            throw new \RuntimeException('duo rollback: signing key id is malformed');
        }
    }

    private static function timeValue(string $value): int {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \RuntimeException("duo rollback: malformed UTC timestamp '$value'");
        }
        return $date->getTimestamp();
    }

    private static function timestamp(): string {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function formatTime(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private static function receiptDirectory(string $root, string $receiptId): string {
        self::assertIdentifier($receiptId, 'receipt id', 32, 64);
        return dirname($root) . '/rollback/' . $receiptId;
    }

    private static function eventPath(string $receiptDir, int $sequence, string $hash): string {
        self::assertHash($hash, 'event hash');
        return $receiptDir . '/events/' . sprintf('%012d-%s.json', $sequence, $hash);
    }
}

/** @return array<string,string> */
function rollback_control_args(array $argv): array {
    $out = [];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $out[$key] = $value;
        }
    }
    return $out;
}

function rollback_control_main(array $argv): int {
    array_shift($argv);
    $action = array_shift($argv) ?? '';
    $args = rollback_control_args($argv);
    $root = $args['root'] ?? '';
    if ($root === '' || $root[0] !== '/') {
        fwrite(STDERR, "duo rollback: --root must be an absolute path\n");
        return 2;
    }
    try {
        $result = match ($action) {
            'init' => RollbackControl::initialize(
                $root,
                isset($args['target-id']) && $args['target-id'] !== '' ? $args['target-id'] : null
            ),
            'install-key' => (function () use ($root, $args): array {
                RollbackControl::installPublicKey(
                    $root,
                    (string) ($args['key-id'] ?? ''),
                    (string) ($args['public-key'] ?? '')
                );
                return ['ok' => true];
            })(),
            'request' => RollbackControl::handleRequest($root, (string) ($args['request'] ?? '')),
            'configure-recovery' => RecoveryExecutor::configureFromFile($root, (string) ($args['config'] ?? '')),
            'recovery-probe' => RecoveryExecutor::probe($root),
            'exclusion-request' => RecoveryExecutor::handleExclusionRequest($root, (string) ($args['request'] ?? '')),
            'checkpoint-request' => CheckpointBundle::handleRequest($root, (string) ($args['request'] ?? '')),
            'code-release-request' => CodeRelease::handleRequest($root, (string) ($args['request'] ?? '')),
            'upload-bundle-request' => UploadBundle::handleRequest($root, (string) ($args['request'] ?? '')),
            'effect-bundle-request' => EffectBundle::handleRequest($root, (string) ($args['request'] ?? '')),
            'execute' => RecoveryExecutor::execute(
                $root,
                (string) ($args['adapter'] ?? ''),
                (string) ($args['operation-id'] ?? ''),
                (int) ($args['attempt'] ?? 0),
                (string) ($args['claimant'] ?? ''),
                (int) ($args['claim-epoch'] ?? 0),
                (string) ($args['input'] ?? '')
            ),
            'authority-status' => RollbackControl::status($root),
            'audit' => RollbackControl::auditEvidence($root),
            'status' => RecoveryExecutor::decorateStatus($root, RollbackControl::status($root)),
            default => throw new \RuntimeException("duo rollback: unknown action '$action'"),
        };
        echo RollbackControl::canonical($result) . "\n";
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }
}

require_once __DIR__ . '/RecoveryExecutor.php';
require_once __DIR__ . '/CheckpointBundle.php';
require_once __DIR__ . '/CodeRelease.php';
require_once __DIR__ . '/UploadBundle.php';
require_once __DIR__ . '/EffectBundle.php';

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(rollback_control_main($argv));
}
