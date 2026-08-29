<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/Transport.php';

/**
 * The four rollback-authority environment keys, parsed once for every
 * transport that can carry the protocol.
 *
 * `rollback_key_id`, `rollback_signing_key`, `rollback_recovery` and
 * `verified_rollback` used to be parsed inline in `SshTransport::__construct`
 * and validated by three private methods on that class, which is what made
 * SSH the only transport able to answer `VerifiedRollbackProfile::select()`'s
 * seven capability predicates. Moving the parser here — bytes unchanged,
 * including every `env '<name>': …` refusal string, which AGENTS.md rule 8
 * pins — is what lets a second transport accept the same keys with the same
 * refusals instead of drifting its own dialect.
 *
 * The ORDER of the checks below is part of that contract: an environment with
 * several mistakes must keep reporting the same first one it reported before.
 */
final class RecoveryConfig {
    private ?string $keyId;
    private ?string $signingKeyPath;
    /** @var ?array<string,mixed> */
    private ?array $recovery;
    /** @var ?array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} */
    private ?array $verified;

    /**
     * @param ?array<string,mixed> $recovery
     * @param ?array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} $verified
     */
    private function __construct(?string $keyId, ?string $signingKeyPath, ?array $recovery, ?array $verified) {
        $this->keyId = $keyId;
        $this->signingKeyPath = $signingKeyPath;
        $this->recovery = $recovery;
        $this->verified = $verified;
    }

    /** The four keys this parser owns, for a transport that must gate on their presence. */
    public const KEYS = ['rollback_key_id', 'rollback_recovery', 'rollback_signing_key', 'verified_rollback'];

    /** @param array<string,mixed> $cfg */
    public static function declaredIn(array $cfg): bool {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $cfg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $cfg the environment's raw config row
     * @param string $dir the directory the config was defined in, for
     *        resolving a relative `rollback_signing_key`
     */
    public static function parse(string $name, array $cfg, string $dir): self {
        $keyId = $cfg['rollback_key_id'] ?? null;
        $keyPath = $cfg['rollback_signing_key'] ?? null;
        if (($keyId === null) !== ($keyPath === null)) {
            throw new \RuntimeException(
                "env '$name': rollback_key_id and rollback_signing_key must be configured together"
            );
        }
        if ($keyId !== null && (!is_string($keyId)
            || strlen($keyId) < 1 || strlen($keyId) > 64
            || preg_match('/^[A-Za-z0-9._-]+$/', $keyId) !== 1)) {
            throw new \RuntimeException("env '$name': rollback_key_id must match [A-Za-z0-9._-]{1,64}");
        }
        if ($keyPath !== null && (!is_string($keyPath) || $keyPath === '')) {
            throw new \RuntimeException("env '$name': rollback_signing_key must be a non-empty path string");
        }
        $resolvedKeyId = is_string($keyId) ? $keyId : null;
        $resolvedKeyPath = is_string($keyPath)
            ? Transport::resolvePath($dir, $keyPath)
            : null;

        $recovery = $cfg['rollback_recovery'] ?? null;
        if ($recovery !== null && !is_array($recovery)) {
            throw new \RuntimeException("env '$name': rollback_recovery must be an object");
        }
        $normalizedRecovery = is_array($recovery)
            ? self::validateRecoveryConfig($name, $recovery)
            : null;
        if ($normalizedRecovery !== null && ($resolvedKeyId === null || $resolvedKeyPath === null)) {
            throw new \RuntimeException(
                "env '$name': rollback_recovery requires rollback_key_id + rollback_signing_key"
            );
        }

        $verified = $cfg['verified_rollback'] ?? null;
        if ($verified !== null && !is_array($verified)) {
            throw new \RuntimeException("env '$name': verified_rollback must be an object");
        }
        $normalizedVerified = is_array($verified)
            ? self::validateVerifiedRollback($name, $verified)
            : null;
        if ($normalizedVerified !== null && $normalizedRecovery === null) {
            throw new \RuntimeException("env '$name': verified_rollback requires rollback_recovery");
        }

        return new self($resolvedKeyId, $resolvedKeyPath, $normalizedRecovery, $normalizedVerified);
    }

    public function configured(): bool {
        return $this->keyId !== null && $this->signingKeyPath !== null;
    }

    public function keyId(): ?string {
        return $this->keyId;
    }

    public function signingKeyPath(): ?string {
        return $this->signingKeyPath;
    }

    public function recoveryConfigured(): bool {
        return $this->recovery !== null;
    }

    public function providerConfigured(string $provider): bool {
        return is_array($this->recovery) && array_key_exists($provider, $this->recovery);
    }

    /** @return ?array<string,mixed> */
    public function recovery(): ?array {
        return $this->recovery;
    }

    /** @return ?array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} */
    public function verified(): ?array {
        return $this->verified;
    }

    /** The ` rollback_key_id=… rollback_recovery=… verified_rollback=…` tail of `wprism envs`. */
    public function describeSuffix(): string {
        $rollback = $this->keyId !== null ? " rollback_key_id={$this->keyId}" : '';
        $recovery = $this->recovery !== null ? ' rollback_recovery=configured' : '';
        $verified = $this->verified !== null ? ' verified_rollback=configured' : '';
        return "{$rollback}{$recovery}{$verified}";
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function validateRecoveryConfig(string $env, array $config): array {
        $expected = ['adapters', 'exclusion_provider', 'timeout_seconds'];
        if (array_key_exists('checkpoint_provider', $config)) {
            $expected[] = 'checkpoint_provider';
        }
        if (array_key_exists('code_release_provider', $config)) {
            $expected[] = 'code_release_provider';
        }
        if (array_key_exists('upload_provider', $config)) {
            $expected[] = 'upload_provider';
        }
        if (array_key_exists('effect_provider', $config)) {
            $expected[] = 'effect_provider';
        }
        sort($expected, SORT_STRING);
        $actual = array_keys($config);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                "env '$env': rollback_recovery requires adapters, exclusion_provider, timeout_seconds, and optional checkpoint_provider/code_release_provider/upload_provider/effect_provider"
            );
        }
        $provider = self::validateCommand($env, $config['exclusion_provider'] ?? null, 'exclusion_provider');
        $adapters = $config['adapters'] ?? null;
        if (!is_array($adapters) || array_is_list($adapters)) {
            throw new \RuntimeException("env '$env': rollback_recovery.adapters must be an object");
        }
        $required = ['code_restore', 'database_restore', 'prior_verify', 'storage_restore'];
        $adapterNames = array_keys($adapters);
        sort($adapterNames, SORT_STRING);
        if ($adapterNames !== $required) {
            throw new \RuntimeException(
                "env '$env': rollback_recovery.adapters requires exactly " . implode(', ', $required)
            );
        }
        $validated = [];
        foreach ($required as $name) {
            $validated[$name] = self::validateCommand($env, $adapters[$name], "adapters.$name");
        }
        $timeout = $config['timeout_seconds'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 60) {
            throw new \RuntimeException("env '$env': rollback_recovery.timeout_seconds must be 1..60");
        }
        $normalized = [
            'adapters' => $validated,
            'exclusion_provider' => $provider,
            'format' => 'wprism-recovery-config/v1',
            'timeout_seconds' => $timeout,
        ];
        if (array_key_exists('checkpoint_provider', $config)) {
            $normalized['checkpoint_provider'] = self::validateCommand(
                $env,
                $config['checkpoint_provider'],
                'checkpoint_provider'
            );
        }
        if (array_key_exists('code_release_provider', $config)) {
            $normalized['code_release_provider'] = self::validateCommand(
                $env,
                $config['code_release_provider'],
                'code_release_provider'
            );
        }
        if (array_key_exists('upload_provider', $config)) {
            $normalized['upload_provider'] = self::validateCommand(
                $env,
                $config['upload_provider'],
                'upload_provider'
            );
        }
        if (array_key_exists('effect_provider', $config)) {
            $normalized['effect_provider'] = self::validateCommand(
                $env,
                $config['effect_provider'],
                'effect_provider'
            );
        }
        return $normalized;
    }

    /**
     * Controller-owned policy for the automatic profile. Keeping this outside
     * rollback_recovery is deliberate: adoption copies only target provider
     * argv, while retention and the external KMS key label remain a local
     * promotion decision.
     *
     * @param array<string,mixed> $config
     * @return array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int}
     */
    public static function validateVerifiedRollback(string $env, array $config): array {
        $keys = array_keys($config);
        sort($keys, SORT_STRING);
        if ($keys !== ['claim_ttl_seconds', 'encryption_key_id', 'retention_seconds']) {
            throw new \RuntimeException(
                "env '$env': verified_rollback requires exactly claim_ttl_seconds, encryption_key_id, retention_seconds"
            );
        }
        $ttl = $config['claim_ttl_seconds'];
        if (!is_int($ttl) || $ttl < 30 || $ttl > 3600) {
            throw new \RuntimeException("env '$env': verified_rollback.claim_ttl_seconds must be 30..3600");
        }
        $retention = $config['retention_seconds'];
        if (!is_int($retention) || $retention < 60 || $retention > 31536000) {
            throw new \RuntimeException("env '$env': verified_rollback.retention_seconds must be 60..31536000");
        }
        $key = $config['encryption_key_id'];
        if (!is_string($key)
            || preg_match('/^[A-Za-z0-9._:@+-]{1,128}$/', $key) !== 1) {
            throw new \RuntimeException("env '$env': verified_rollback.encryption_key_id is invalid");
        }
        return [
            'claim_ttl_seconds' => $ttl,
            'encryption_key_id' => $key,
            'retention_seconds' => $retention,
        ];
    }

    /** @return list<string> */
    public static function validateCommand(string $env, mixed $value, string $label): array {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \RuntimeException("env '$env': rollback_recovery.$label must be a non-empty argv array");
        }
        foreach ($value as $index => $arg) {
            if (!is_string($arg) || $arg === '' || str_contains($arg, "\0")) {
                throw new \RuntimeException("env '$env': rollback_recovery.$label argv[$index] is invalid");
            }
        }
        if ($value[0][0] !== '/') {
            throw new \RuntimeException("env '$env': rollback_recovery.$label executable must be absolute");
        }
        return array_values($value);
    }
}
