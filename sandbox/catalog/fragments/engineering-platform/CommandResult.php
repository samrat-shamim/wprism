<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

final class CommandResult
{
    public function __construct(private readonly string $root) {}

    /**
     * @param list<string> $command
     * @return array{exit:int,receipt:array<string,mixed>}
     */
    public function run(string $id, array $command): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $id) !== 1 || $command === []) {
            throw new CatalogException('command result requires a valid id and nonempty argv');
        }
        $candidate = $this->candidateSha();
        $dirtyBefore = $this->gitStatus();
        $started = hrtime(true);
        $process = proc_open(
            $command,
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $this->root,
            null,
            ['bypass_shell' => true],
        );
        $exit = is_resource($process) ? proc_close($process) : 69;
        $dirtyAfter = $this->gitStatus();
        $state = $exit === 0 ? 'pass' : (in_array($exit, [2, 69], true) ? 'infra_error' : 'fail');
        if ($dirtyAfter !== $dirtyBefore) {
            $state = 'infra_error';
        }
        return [
            'exit' => $exit,
            'receipt' => [
                'format' => 'duo-command-result/v1',
                'id' => $id,
                'state' => $state,
                'candidate_sha' => $candidate,
                'candidate_dirty' => $dirtyBefore !== '',
                'workspace_stable' => $dirtyBefore === $dirtyAfter,
                'argv_sha256' => 'sha256:' . hash('sha256', $this->canonical($command)),
                'exit_code' => $exit,
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ],
        ];
    }

    /** @param array<string,mixed> $receipt */
    public function publish(array $receipt, string $relative): void
    {
        if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative) !== 1 || str_contains($relative, '..')) {
            throw new CatalogException('command result path must be artifacts-relative');
        }
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new CatalogException('cannot create command result directory');
        }
        $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0600)) {
            throw new CatalogException('cannot publish command result');
        }
    }

    private function candidateSha(): string
    {
        $sha = trim($this->capture(['git', 'rev-parse', 'HEAD']));
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new CatalogException('command result candidate is not a full SHA');
        }
        return $sha;
    }

    private function gitStatus(): string
    {
        return $this->capture(['git', 'status', '--porcelain=v1', '--untracked-files=all']);
    }

    /** @param list<string> $command */
    private function capture(array $command): string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot inspect command result workspace');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new CatalogException('cannot inspect command result workspace');
        }
        return $stdout;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
