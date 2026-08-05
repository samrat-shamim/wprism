<?php
namespace Duo\Orchestrator;

/** Runs wp-cli directly on this machine: `wp --path=<wp_path> …`. */
final class LocalTransport extends Transport {
    private string $wpPath;

    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        $this->wpPath = self::requireKey($cfg, $name, 'wp_path');
    }

    public function describe(): string {
        return "local  wp_path={$this->wpPath} repo_path={$this->repoPath}";
    }

    protected function wpCommand(array $wpArgs): string {
        return self::tokens(array_merge(['wp', '--path=' . $this->wpPath], $wpArgs));
    }

    protected function rawCommand(string $script): string {
        // proc_open()/passthru() already run string commands via the system
        // shell, so a raw snippet needs no extra wrapping here.
        return $script;
    }
}
