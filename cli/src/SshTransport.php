<?php
namespace Duo\Orchestrator;

/** Runs wp-cli over ssh: `ssh <host> 'cd <wp_path> && wp …'`. */
final class SshTransport extends Transport {
    private string $host;
    private string $wpPath;

    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        $this->host = self::requireKey($cfg, $name, 'host');
        $this->wpPath = self::requireKey($cfg, $name, 'wp_path');
    }

    public function describe(): string {
        return "ssh    host={$this->host} wp_path={$this->wpPath} repo_path={$this->repoPath}";
    }

    protected function wpCommand(array $wpArgs): string {
        $remote = 'cd ' . self::esc($this->wpPath) . ' && wp ' . self::tokens($wpArgs);
        return 'ssh ' . self::esc($this->host) . ' ' . self::esc($remote);
    }

    protected function rawCommand(string $script): string {
        // ssh already hands a single command-line argument to the remote
        // login shell for interpretation, same as the mission's `wp …`
        // pattern — no extra `bash -c` wrapper needed.
        return 'ssh ' . self::esc($this->host) . ' ' . self::esc($script);
    }
}
