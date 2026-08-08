<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Recovery\RollbackControl;

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
        $runtime = self::runtimePath($transport);
        $root = self::controlRoot($transport);
        $script = 'if [ ! -f ' . escapeshellarg($runtime) . ' ]; then exit 44; fi; '
            . 'php ' . escapeshellarg($runtime) . ' status --root=' . escapeshellarg($root);
        $result = $transport->captureRaw($script);
        if ($result['exit'] === 44) {
            return ['available' => false, 'ok' => true, 'active' => false];
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
            || RollbackControl::canonical($decoded) . "\n" !== $result['stdout']
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
        $receipt = $fields + [
            'format' => RollbackControl::RECEIPT_FORMAT,
            'generation' => (int) ($status['generation'] ?? 0) + 1,
            'receipt_id' => bin2hex(random_bytes(24)),
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
            hash('sha256', RollbackControl::canonical($receipt)),
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
        return $this->send([
            'action' => 'append',
            'event' => RollbackControl::sign($event, $this->keyId, $this->secretKey),
            'receipt' => null,
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
        $local = tempnam(sys_get_temp_dir(), 'duo-rollback-request-');
        if ($local === false) {
            throw new \RuntimeException('duo rollback: could not allocate request handoff');
        }
        $token = bin2hex(random_bytes(16));
        $remote = '/tmp/duo-rollback-request-' . $token . '.json';
        try {
            @chmod($local, 0600);
            $bytes = RollbackControl::canonical($request) . "\n";
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
                . 'php ' . escapeshellarg($runtime) . ' request --root=' . escapeshellarg($root)
                . ' --request="$request"';
            $result = $this->transport->captureRaw($script);
            if ($result['exit'] !== 0) {
                $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
                throw new \RuntimeException('duo rollback: target request refused' . ($detail !== '' ? ': ' . $detail : ''));
            }
            $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)
                || RollbackControl::canonical($decoded) . "\n" !== $result['stdout']
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
