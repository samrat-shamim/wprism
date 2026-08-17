<?php
declare(strict_types=1);

namespace Duo;

/**
 * Verify the external SSH checkpoint authority through the recovery runtime
 * installed by adoption. The control root is agent-owned configuration: no
 * CLI flag, repository argument, or scope document may choose another trust
 * root. Runtime stderr is deliberately never forwarded into public errors.
 */
final class ScopedPromotionAuthority {
    private const CONFIG_FORMAT = 'duo-scoped-promotion-control/v1';
    private const WITNESS_FORMAT = 'duo-scoped-promotion-witness/v1';
    private const RECEIPT_FORMAT = 'duo-scoped-promotion-receipt/v1';

    /**
     * @param list<string> $allowedStates
     * @return array<string,mixed>
     */
    public static function require_installed(
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash,
        array $allowedStates,
        ?bool $allowDeletes = null
    ): array {
        $config = self::installed_config();
        $root = (string) $config['control_root'];
        $runtime = $root . '/recovery-runtime/rollback-control.php';
        self::assert_regular_file($runtime, 'scoped promotion recovery runtime');

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, $runtime, 'scoped-promotion-witness', '--root=' . $root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('duo: scoped promotion authority witness is unavailable');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unset($stderr);
        if ($exit !== 0 || !is_string($stdout) || strlen($stdout) > 65536) {
            throw new \RuntimeException('duo: scoped promotion authority witness is unavailable or non-green');
        }
        try {
            $witness = json_decode(trim($stdout), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $_failure) {
            throw new \RuntimeException('duo: scoped promotion authority witness is malformed');
        }
        if (!is_array($witness) || array_is_list($witness)) {
            throw new \RuntimeException('duo: scoped promotion authority witness is malformed');
        }
        return self::validate(
            $witness,
            $owner,
            $artifactHash,
            $receiptHash,
            $scopeHash,
            $allowedStates,
            $allowDeletes
        );
    }

    /**
     * Pure closed-schema validator retained as a focused offline test seam.
     * It is not an alternate trust-root loader; production callers first run
     * the fixed installed runtime above.
     *
     * @param array<string,mixed> $witness
     * @param list<string> $allowedStates
     * @return array<string,mixed>
     */
    public static function validate(
        array $witness,
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash,
        array $allowedStates,
        ?bool $allowDeletes = null
    ): array {
        self::assert_hash($artifactHash, 'artifact hash');
        self::assert_hash($receiptHash, 'receipt payload hash');
        self::assert_hash($scopeHash, 'scope hash');
        if ($owner === '' || strlen($owner) > 128 || preg_match('/^[A-Za-z0-9._:@+-]+$/D', $owner) !== 1) {
            throw new \RuntimeException('duo: scoped promotion authority owner is malformed');
        }
        $expectedKeys = [
            'active', 'allow_deletes', 'artifact_hash', 'exclusion_state', 'format', 'ok', 'owner',
            'generation', 'receipt_format', 'receipt_id', 'receipt_payload_sha256',
            'recovery_ready', 'scope_hash', 'signing_key_id', 'state',
            'target_id', 'terminal',
        ];
        $keys = array_keys($witness);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || ($witness['format'] ?? null) !== self::WITNESS_FORMAT
            || ($witness['receipt_format'] ?? null) !== self::RECEIPT_FORMAT
            || ($witness['active'] ?? null) !== true
            || !is_bool($witness['allow_deletes'] ?? null)
            || ($witness['ok'] ?? null) !== true
            || ($witness['recovery_ready'] ?? null) !== true
            || ($witness['exclusion_state'] ?? null) !== 'held'
            || !is_int($witness['generation'] ?? null) || (int) $witness['generation'] < 1
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) ($witness['signing_key_id'] ?? '')) !== 1
            || preg_match('/^[A-Za-z0-9._-]{32,64}$/D', (string) ($witness['receipt_id'] ?? '')) !== 1
            || preg_match('/^[A-Za-z0-9._-]{32}$/D', (string) ($witness['target_id'] ?? '')) !== 1
            || ($witness['terminal'] ?? null) !== in_array((string) ($witness['state'] ?? ''), ['committed', 'rolled_back'], true)
            || !hash_equals($owner, (string) ($witness['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($witness['artifact_hash'] ?? ''))
            || !hash_equals($receiptHash, (string) ($witness['receipt_payload_sha256'] ?? ''))
            || !hash_equals($scopeHash, (string) ($witness['scope_hash'] ?? ''))
            || ($allowDeletes !== null && $witness['allow_deletes'] !== $allowDeletes)
            || !in_array((string) ($witness['state'] ?? ''), $allowedStates, true)) {
            throw new \RuntimeException(
                'duo: scoped promotion authority does not match the exact held checkpoint generation'
            );
        }
        return $witness;
    }

    /** @return array{control_root:string,format:string} */
    private static function installed_config(): array {
        $path = dirname(__DIR__, 2) . '/scoped-promotion-control.json';
        self::assert_regular_file($path, 'scoped promotion control configuration');
        $stat = @lstat($path);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0777) !== 0600) {
            throw new \RuntimeException('duo: scoped promotion control configuration is not protected mode 0600');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > 4096) {
            throw new \RuntimeException('duo: scoped promotion control configuration is unreadable');
        }
        try {
            $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $_failure) {
            throw new \RuntimeException('duo: scoped promotion control configuration is malformed');
        }
        $keys = is_array($config) ? array_keys($config) : [];
        sort($keys, SORT_STRING);
        $root = is_array($config) ? ($config['control_root'] ?? null) : null;
        if ($keys !== ['control_root', 'format']
            || ($config['format'] ?? null) !== self::CONFIG_FORMAT
            || !is_string($root) || $root === '' || $root[0] !== '/' || str_contains($root, "\0")
            || is_link($root) || !is_dir($root)) {
            throw new \RuntimeException('duo: scoped promotion control configuration is invalid');
        }
        return ['control_root' => rtrim($root, '/'), 'format' => self::CONFIG_FORMAT];
    }

    private static function assert_regular_file(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path)) {
            throw new \RuntimeException("duo: $label is absent or unsafe");
        }
    }

    private static function assert_hash(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException("duo: scoped promotion $label is malformed");
        }
    }
}
