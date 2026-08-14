<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/** Runs the offline syntax and configuration ratchets with lock-installed tools. */
final class Quality
{
    private const SCOPE = 'sandbox/catalog/fragments/engineering-platform/quality-scope.json';
    private const RECEIPT = 'artifacts/bootstrap-dev/receipt.json';

    public function __construct(private readonly string $root) {}

    /** @return list<string> */
    public function governedShellPaths(): array
    {
        $bytes = file_get_contents($this->root . '/' . self::SCOPE);
        if (!is_string($bytes)) {
            throw new CatalogException('quality scope is absent');
        }
        try {
            $document = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('quality scope is invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($document)
            || array_keys($document) !== ['format', 'shell']
            || ($document['format'] ?? null) !== 'duo-quality-scope/v1'
            || !is_array($document['shell'] ?? null)
            || !array_is_list($document['shell'])
            || $document['shell'] === []) {
            throw new CatalogException('quality scope is malformed or empty');
        }
        $paths = [];
        foreach ($document['shell'] as $path) {
            if (!is_string($path)
                || preg_match('~^(?:sandbox|scripts)/[A-Za-z0-9._/-]+\.sh$~D', $path) !== 1
                || str_contains($path, '..')
                || !is_file($this->root . '/' . $path)) {
                throw new CatalogException('quality scope contains an invalid shell path');
            }
            $paths[] = $path;
        }
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        if ($paths !== $sorted || count($paths) !== count(array_unique($paths))) {
            throw new CatalogException('quality scope shell paths must be sorted and unique');
        }
        return $paths;
    }

    public function run(): void
    {
        $tools = $this->tools();
        foreach ($this->platformPhpFiles() as $path) {
            $this->command(['php', '-l', $path]);
        }
        foreach ($this->trackedJsonFiles() as $path) {
            $bytes = file_get_contents($this->root . '/' . $path);
            if (!is_string($bytes)) {
                throw new CatalogException("cannot read JSON source $path");
            }
            try {
                json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new CatalogException("invalid JSON source $path: " . $exception->getMessage());
            }
        }
        $shell = $this->governedShellPaths();
        $this->command(array_merge([$tools['shellcheck'], '--severity=warning'], $shell));
        $this->command(array_merge([$tools['shfmt'], '-d', '-i', '4', '-ci', '-sr'], $shell));
        $workflows = $this->workflowFiles();
        if ($workflows === []) {
            throw new CatalogException('no GitHub Actions workflows are tracked');
        }
        $this->command(array_merge([
            $tools['actionlint'],
            '-config-file=' . __DIR__ . '/actionlint.yaml',
            '-shellcheck=' . $tools['shellcheck'],
        ], $workflows));
        $this->command(['php', 'sandbox/catalog/fragments/engineering-platform/catalog.php', 'validate']);
        $this->command(['php', 'sandbox/catalog/fragments/engineering-platform/command-contract.php', 'validate']);
    }

    /** @return array{actionlint:string,shellcheck:string,shfmt:string} */
    private function tools(): array
    {
        $bytes = file_get_contents($this->root . '/' . self::RECEIPT);
        if (!is_string($bytes)) {
            throw new CatalogException('bootstrap receipt is absent; run make bootstrap-dev');
        }
        try {
            $receipt = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('bootstrap receipt is invalid JSON: ' . $exception->getMessage());
        }
        $expectedLock = (new Toolchain($this->root))->lockDigest();
        if (!is_array($receipt)
            || ($receipt['format'] ?? null) !== 'duo-development-bootstrap-receipt/v1'
            || ($receipt['toolchain_lock_sha256'] ?? null) !== $expectedLock
            || !is_array($receipt['locked_tools'] ?? null)) {
            throw new CatalogException('bootstrap receipt is stale or malformed; run make bootstrap-dev');
        }
        $tools = [];
        foreach (['actionlint', 'shellcheck', 'shfmt'] as $name) {
            $path = $this->root . '/artifacts/bootstrap-dev/bin/' . $name;
            $row = $receipt['locked_tools'][$name] ?? null;
            $digest = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_array($row)
                || !is_string($row['executable_sha256'] ?? null)
                || !is_string($digest)
                || !hash_equals($row['executable_sha256'], 'sha256:' . $digest)
                || !is_executable($path)) {
                throw new CatalogException("lock-installed tool $name is absent or changed; run make bootstrap-dev");
            }
            $tools[$name] = $path;
        }
        return $tools;
    }

    /** @return list<string> */
    private function platformPhpFiles(): array
    {
        $root = $this->root . '/sandbox/catalog/fragments/engineering-platform';
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $paths[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return list<string> */
    private function trackedJsonFiles(): array
    {
        $output = $this->command(['git', 'ls-files', '-z', '--', '*.json'], true);
        $paths = array_values(array_filter(explode("\0", $output), static fn(string $path): bool => $path !== ''));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $output = $this->command(['git', 'ls-files', '-z', '--', '.github/workflows/*.yml', '.github/workflows/*.yaml'], true);
        $paths = array_values(array_filter(explode("\0", $output), static fn(string $path): bool => $path !== ''));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param list<string> $argv */
    private function command(array $argv, bool $capture = false): string
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            [
                'PATH' => $this->root . '/artifacts/bootstrap-dev/bin:' . (string) getenv('PATH'),
                'LC_ALL' => 'C',
                'TZ' => 'UTC',
            ],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start quality command');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (!is_string($stdout) || !is_string($stderr) || $exit !== 0) {
            $message = trim((is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : ''));
            throw new CatalogException('quality command failed: ' . implode(' ', $argv) . ($message === '' ? '' : "\n" . $message));
        }
        return $capture ? $stdout : '';
    }
}
