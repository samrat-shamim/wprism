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
            'recovery_ready', 'resources_inventory_sha256', 'signing_key_id',
            'state', 'target_id', 'terminal',
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
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($witness['resources_inventory_sha256'] ?? '')) !== 1
            || !hash_equals($owner, (string) ($witness['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($witness['artifact_hash'] ?? ''))
            || !hash_equals($receiptHash, (string) ($witness['receipt_payload_sha256'] ?? ''))) {
            throw new \RuntimeException(
                'wprism: verified promotion authority does not match the exact held full-recovery generation'
            );
        }
        return $witness;
    }

    /**
     * Re-prove the plan-bound action/effect authority after the target's fresh
     * pre-mutation plan. The receipt may include lifecycle rows only when the
     * controller actually ran a code transition, so compare the two closed
     * projections rather than trusting a caller-supplied boolean.
     */
    public static function assert_plan_resources(array $plan, array $witness): void {
        $code = $plan['code'] ?? null;
        $uploads = $plan['uploads_inventory'] ?? null;
        $effects = $plan['effects_inventory'] ?? null;
        $lifecycle = $plan['lifecycle_effects_inventory'] ?? null;
        $actions = $plan['selected_actions'] ?? null;
        if (!is_array($code)
            || !is_array($uploads) || !array_is_list($uploads)
            || !is_array($effects) || !array_is_list($effects)
            || !is_array($lifecycle) || !array_is_list($lifecycle)
            || !is_array($actions) || !array_is_list($actions)) {
            throw new \RuntimeException(
                'wprism: verified promotion plan lacks its plan-bound recovery inventories'
            );
        }
        foreach ($actions as $action) {
            $keys = is_array($action) ? array_keys($action) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['declaration_hash', 'index', 'manifest']
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($action['declaration_hash'] ?? '')) !== 1
                || !is_int($action['index'] ?? null) || (int) $action['index'] < 0
                || !is_string($action['manifest'] ?? null) || $action['manifest'] === '') {
                throw new \RuntimeException(
                    'wprism: verified promotion selected-action evidence is malformed'
                );
            }
        }
        $expected = (string) ($witness['resources_inventory_sha256'] ?? '');
        foreach ([false, true] as $withLifecycle) {
            $selectedEffects = $withLifecycle ? array_merge($effects, $lifecycle) : $effects;
            if ($withLifecycle) {
                usort($selectedEffects, static fn(array $a, array $b): int => strcmp(
                    implode("\0", [
                        (string) ($a['phase'] ?? ''),
                        (string) ($a['manifest'] ?? ''),
                        (string) (($a['effect'] ?? [])['id'] ?? ''),
                    ]),
                    implode("\0", [
                        (string) ($b['phase'] ?? ''),
                        (string) ($b['manifest'] ?? ''),
                        (string) (($b['effect'] ?? [])['id'] ?? ''),
                    ])
                ));
            }
            $actual = hash('sha256', self::recovery_canonical([
                'code' => $code,
                'effects_inventory' => $selectedEffects,
                'selected_actions' => $actions,
                'uploads_inventory' => $uploads,
            ]));
            if (hash_equals($expected, $actual)) {
                return;
            }
        }
        throw new \RuntimeException(
            'wprism: verified promotion receipt resources do not match the fresh target plan selection'
        );
    }

    /**
     * The signed receipt is produced by recovery/CanonicalJson.php, whose
     * compact bytes deliberately differ from repository Canon::encode(). The
     * drop-in cannot load recovery code, so this exact codec twin is guarded
     * against RollbackControl::canonical() by the scoped-promotion regression.
     *
     * @param array<string,mixed> $value
     */
    private static function recovery_canonical(array $value): string {
        try {
            return (string) json_encode(
                self::normalize_recovery_value($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $failure) {
            throw new \RuntimeException(
                'wprism: verified promotion recovery resource encoding failed',
                0,
                $failure
            );
        }
    }

    private static function normalize_recovery_value(mixed $value): mixed {
        if (!is_array($value)) {
            if (is_float($value) || is_resource($value) || is_object($value)) {
                throw new \RuntimeException(
                    'wprism: verified promotion plan contains an unsupported recovery resource value'
                );
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize_recovery_value(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException(
                    'wprism: verified promotion recovery resource object keys must be strings'
                );
            }
            $value[$key] = self::normalize_recovery_value($item);
        }
        return $value;
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
