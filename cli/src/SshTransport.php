<?php
namespace Duo\Orchestrator;

/** Runs wp-cli over ssh: `ssh <host> 'cd <wp_path> && wp …'`. */
final class SshTransport extends Transport {
    private string $host;
    private string $wpPath;
    private ?string $configFile;
    private ?string $rollbackKeyId;
    private ?string $rollbackSigningKey;
    /** @var ?array<string,mixed> */
    private ?array $rollbackRecovery;

    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        $this->host = self::requireKey($cfg, $name, 'host');
        $this->wpPath = self::requireKey($cfg, $name, 'wp_path');
        $config = $cfg['ssh_config'] ?? null;
        if ($config !== null && (!is_string($config) || $config === '')) {
            throw new \RuntimeException("env '$name': optional key 'ssh_config' must be a non-empty path string");
        }
        $this->configFile = is_string($config)
            ? self::resolvePath((string) ($cfg['_dir'] ?? '.'), $config)
            : null;

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
        $this->rollbackKeyId = is_string($keyId) ? $keyId : null;
        $this->rollbackSigningKey = is_string($keyPath)
            ? self::resolvePath((string) ($cfg['_dir'] ?? '.'), $keyPath)
            : null;

        $recovery = $cfg['rollback_recovery'] ?? null;
        if ($recovery !== null && !is_array($recovery)) {
            throw new \RuntimeException("env '$name': rollback_recovery must be an object");
        }
        $this->rollbackRecovery = is_array($recovery)
            ? self::validateRecoveryConfig($name, $recovery)
            : null;
        if ($this->rollbackRecovery !== null && !$this->rollbackConfigured()) {
            throw new \RuntimeException(
                "env '$name': rollback_recovery requires rollback_key_id + rollback_signing_key"
            );
        }
    }

    public function describe(): string {
        $config = $this->configFile !== null ? " ssh_config={$this->configFile}" : '';
        $rollback = $this->rollbackKeyId !== null ? " rollback_key_id={$this->rollbackKeyId}" : '';
        $recovery = $this->rollbackRecovery !== null ? ' rollback_recovery=configured' : '';
        return "ssh    host={$this->host} wp_path={$this->wpPath} repo_path={$this->repoPath}{$config}{$rollback}{$recovery}";
    }

    public function rollbackConfigured(): bool {
        return $this->rollbackKeyId !== null && $this->rollbackSigningKey !== null;
    }

    public function rollbackKeyId(): ?string {
        return $this->rollbackKeyId;
    }

    public function rollbackSigningKeyPath(): ?string {
        return $this->rollbackSigningKey;
    }

    public function recoveryConfigured(): bool {
        return $this->rollbackRecovery !== null;
    }

    public function checkpointConfigured(): bool {
        return is_array($this->rollbackRecovery)
            && array_key_exists('checkpoint_provider', $this->rollbackRecovery);
    }

    public function codeReleaseConfigured(): bool {
        return is_array($this->rollbackRecovery)
            && array_key_exists('code_release_provider', $this->rollbackRecovery);
    }

    public function uploadProviderConfigured(): bool {
        return is_array($this->rollbackRecovery)
            && array_key_exists('upload_provider', $this->rollbackRecovery);
    }

    public function effectProviderConfigured(): bool {
        return is_array($this->rollbackRecovery)
            && array_key_exists('effect_provider', $this->rollbackRecovery);
    }

    /** @return ?array<string,mixed> */
    public function recoveryConfig(): ?array {
        return $this->rollbackRecovery;
    }

    protected function wpCommand(array $wpArgs): string {
        $remote = 'cd ' . self::esc($this->wpPath) . ' && wp ' . self::tokens($wpArgs);
        return $this->sshPrefix() . ' ' . self::esc($this->host) . ' ' . self::esc($remote);
    }

    protected function rawCommand(string $script): string {
        // ssh already hands a single command-line argument to the remote
        // login shell for interpretation, same as the mission's `wp …`
        // pattern — no extra `bash -c` wrapper needed.
        return $this->sshPrefix() . ' ' . self::esc($this->host) . ' ' . self::esc($script);
    }

    /**
     * Copy one host-side file to an exact remote path using the same SSH
     * destination as command transport. Adoption intentionally owns this
     * narrow primitive instead of teaching every transport how to install
     * itself: only SSH describes a pre-existing host with no shared volume.
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function uploadFile(string $localPath, string $remotePath): array {
        $destination = $this->host . ':' . $remotePath;
        $config = $this->configFile !== null ? ' -F ' . self::esc($this->configFile) : '';
        return self::runCapturing('scp' . $config . ' ' . self::esc($localPath) . ' ' . self::esc($destination));
    }

    private function sshPrefix(): string {
        return 'ssh' . ($this->configFile !== null ? ' -F ' . self::esc($this->configFile) : '');
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private static function validateRecoveryConfig(string $env, array $config): array {
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
            'format' => 'duo-recovery-config/v1',
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

    /** @return list<string> */
    private static function validateCommand(string $env, mixed $value, string $label): array {
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
