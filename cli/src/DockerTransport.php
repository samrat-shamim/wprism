<?php
namespace Duo\Orchestrator;

/** Runs wp-cli inside a compose service: `docker compose -f … run --rm -T <service> wp …`. */
final class DockerTransport extends Transport {
    private string $composeFile;
    private ?string $profile;
    private string $service;

    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        $dir = is_string($cfg['_dir'] ?? null) ? $cfg['_dir'] : (getcwd() ?: '.');
        $this->composeFile = self::resolvePath($dir, self::requireKey($cfg, $name, 'compose_file'));
        $profile = $cfg['profile'] ?? null;
        $this->profile = (is_string($profile) && $profile !== '') ? $profile : null;
        $this->service = self::requireKey($cfg, $name, 'service');
    }

    public function describe(): string {
        $profile = $this->profile !== null ? " profile={$this->profile}" : '';
        return "docker compose_file={$this->composeFile}{$profile} service={$this->service} repo_path={$this->repoPath}";
    }

    private function baseTokens(): array {
        $t = ['docker', 'compose', '-f', $this->composeFile];
        if ($this->profile !== null) {
            $t[] = '--profile';
            $t[] = $this->profile;
        }
        return $t;
    }

    protected function wpCommand(array $wpArgs): string {
        return self::tokens(array_merge($this->baseTokens(), ['run', '--rm', '-T', $this->service, 'wp'], $wpArgs));
    }

    protected function rawCommand(string $script): string {
        // `run`'s trailing args become the container's argv directly, not a
        // shell command line — go through bash -c for test operators, &&,
        // pipes, etc. (same pattern the spike scripts use for raw commands
        // against the cli-* services).
        return self::tokens(array_merge($this->baseTokens(), ['run', '--rm', '-T', $this->service, 'bash', '-c', $script]));
    }
}
