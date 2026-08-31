<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Verify the deletion-admitting full-promotion receipt through the fixed
 * recovery runtime installed by adoption. The signed v3 receipt, rather than
 * an operator-supplied flag, is the authority for destructive continuation.
 */
final class VerifiedPromotionAuthority {
    private const CONFIG_FORMAT = 'wprism-scoped-promotion-control/v1';
    private const WITNESS_FORMAT = 'wprism-verified-promotion-witness/v1';
    private const RECEIPT_FORMAT = 'wprism-rollback-receipt/v3';

    /** @return array<string,mixed> */
    public static function require_installed(
        string $owner,
        string $artifactHash,
        string $receiptHash
    ): array {
        $config = self::installed_config();
        $root = (string) $config['control_root'];
        $runtime = $root . '/recovery-runtime/rollback-control.php';
        self::assert_regular_file($runtime, 'verified promotion recovery runtime');

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, $runtime, 'verified-promotion-witness', '--root=' . $root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('wprism: verified promotion authority witness is unavailable');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unset($stderr);
        if ($exit !== 0 || !is_string($stdout) || strlen($stdout) > 65536) {
            throw new \RuntimeException('wprism: verified promotion authority witness is unavailable or non-green');
        }
        try {
            $witness = json_decode(trim($stdout), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $_failure) {
            throw new \RuntimeException('wprism: verified promotion authority witness is malformed');
        }
        if (!is_array($witness) || array_is_list($witness)) {
            throw new \RuntimeException('wprism: verified promotion authority witness is malformed');
        }
        return self::validate($witness, $owner, $artifactHash, $receiptHash);
    }

    /**
     * @param array<string,mixed> $witness
     * @return array<string,mixed>
     */
    public static function validate(
        array $witness,
        string $owner,
        string $artifactHash,
        string $receiptHash
    ): array {
        self::assert_hash($artifactHash, 'artifact hash');
        self::assert_hash($receiptHash, 'receipt payload hash');
        if ($owner === '' || strlen($owner) > 128 || preg_match('/^[A-Za-z0-9._:@+-]+$/D', $owner) !== 1) {
            throw new \RuntimeException('wprism: verified promotion authority owner is malformed');
        }
        $expectedKeys = [
            'active', 'allow_deletes', 'artifact_hash', 'exclusion_state', 'format', 'ok', 'owner',
            'generation', 'receipt_format', 'receipt_id', 'receipt_payload_sha256',
            'recovery_ready', 'signing_key_id', 'state', 'target_id', 'terminal',
        ];
        $keys = array_keys($witness);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || ($witness['format'] ?? null) !== self::WITNESS_FORMAT
            || ($witness['receipt_format'] ?? null) !== self::RECEIPT_FORMAT
            || ($witness['active'] ?? null) !== true
            || ($witness['allow_deletes'] ?? null) !== true
            || ($witness['ok'] ?? null) !== true
            || ($witness['recovery_ready'] ?? null) !== true
            || ($witness['exclusion_state'] ?? null) !== 'held'
            || ($witness['state'] ?? null) !== 'promoting'
            || ($witness['terminal'] ?? null) !== false
            || !is_int($witness['generation'] ?? null) || (int) $witness['generation'] < 1
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) ($witness['signing_key_id'] ?? '')) !== 1
            || preg_match('/^[A-Za-z0-9._-]{32,64}$/D', (string) ($witness['receipt_id'] ?? '')) !== 1
            || preg_match('/^[A-Za-z0-9._-]{32}$/D', (string) ($witness['target_id'] ?? '')) !== 1
            || !hash_equals($owner, (string) ($witness['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($witness['artifact_hash'] ?? ''))
            || !hash_equals($receiptHash, (string) ($witness['receipt_payload_sha256'] ?? ''))) {
            throw new \RuntimeException(
                'wprism: verified promotion authority does not match the exact held full-recovery generation'
            );
        }
        return $witness;
    }

    /** @return array{control_root:string,format:string} */
    private static function installed_config(): array {
        $path = dirname(__DIR__, 2) . '/scoped-promotion-control.json';
        self::assert_regular_file($path, 'verified promotion control configuration');
        $stat = @lstat($path);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0777) !== 0600) {
            throw new \RuntimeException('wprism: verified promotion control configuration is not protected mode 0600');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > 4096) {
            throw new \RuntimeException('wprism: verified promotion control configuration is unreadable');
        }
        try {
            $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $_failure) {
            throw new \RuntimeException('wprism: verified promotion control configuration is malformed');
        }
        $keys = is_array($config) ? array_keys($config) : [];
        sort($keys, SORT_STRING);
        $root = is_array($config) ? ($config['control_root'] ?? null) : null;
        if ($keys !== ['control_root', 'format']
            || ($config['format'] ?? null) !== self::CONFIG_FORMAT
            || !is_string($root) || $root === '' || $root[0] !== '/' || str_contains($root, "\0")
            || is_link($root) || !is_dir($root)) {
            throw new \RuntimeException('wprism: verified promotion control configuration is invalid');
        }
        return ['control_root' => rtrim($root, '/'), 'format' => self::CONFIG_FORMAT];
    }

    private static function assert_regular_file(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path)) {
            throw new \RuntimeException("wprism: $label is absent or unsafe");
        }
    }

    private static function assert_hash(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException("wprism: verified promotion $label is malformed");
        }
    }
}
