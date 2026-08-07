<?php
namespace Duo\Orchestrator;

/** Runs wp-cli over ssh: `ssh <host> 'cd <wp_path> && wp …'`. */
final class SshTransport extends Transport {
    private string $host;
    private string $wpPath;
    private ?string $configFile;

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
    }

    public function describe(): string {
        $config = $this->configFile !== null ? " ssh_config={$this->configFile}" : '';
        return "ssh    host={$this->host} wp_path={$this->wpPath} repo_path={$this->repoPath}{$config}";
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
}
