<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/** Installs the non-Composer quality tools from the reviewed content lock. */
final class Toolchain
{
    private const LOCK = 'sandbox/catalog/fragments/engineering-platform/toolchain-lock.json';

    public function __construct(private readonly string $root) {}

    public function platformId(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            default => throw new CatalogException('unsupported development-tool operating system: ' . PHP_OS_FAMILY),
        };
        $machine = strtolower(php_uname('m'));
        $architecture = match ($machine) {
            'amd64', 'x86_64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new CatalogException('unsupported development-tool architecture: ' . $machine),
        };
        return $os . '-' . $architecture;
    }

    public function lockDigest(): string
    {
        $digest = hash_file('sha256', $this->root . '/' . self::LOCK);
        if (!is_string($digest)) {
            throw new CatalogException('cannot hash development toolchain lock');
        }
        return 'sha256:' . $digest;
    }

    /** @return array<string,array{version:string,source_sha256:string,executable_sha256:string,identity:string}> */
    public function install(): array
    {
        $lock = $this->lock();
        $platform = $this->platformId();
        $output = $this->root . '/artifacts/bootstrap-dev/bin';
        if (!is_dir($output) && !mkdir($output, 0700, true) && !is_dir($output)) {
            throw new CatalogException('cannot create development tool directory');
        }
        $installed = [];
        foreach ($lock as $name => $tool) {
            $source = $tool['platforms'][$platform] ?? null;
            if (!is_array($source)) {
                throw new CatalogException("development tool $name has no source for $platform");
            }
            $download = tempnam($output, '.download.');
            if (!is_string($download)) {
                throw new CatalogException('cannot create development tool download');
            }
            try {
                $this->download($source['url'], $download);
                $actual = hash_file('sha256', $download);
                if (!is_string($actual) || !hash_equals($source['sha256'], $actual)) {
                    throw new CatalogException("development tool $name source digest mismatch");
                }
                $bytes = $source['archive'] === 'raw'
                    ? file_get_contents($download)
                    : $this->extract($download, $source['member']);
                if (!is_string($bytes) || $bytes === '') {
                    throw new CatalogException("development tool $name payload is empty");
                }
                $this->publishExecutable($output . '/' . $name, $bytes);
            } finally {
                if (is_file($download)) {
                    unlink($download);
                }
            }
            $identity = $this->command(array_merge([$output . '/' . $name], $tool['probe']));
            $binaryDigest = hash_file('sha256', $output . '/' . $name);
            if (!is_string($binaryDigest)) {
                throw new CatalogException("cannot hash installed development tool $name");
            }
            $installed[$name] = [
                'version' => $tool['version'],
                'source_sha256' => 'sha256:' . $source['sha256'],
                'executable_sha256' => 'sha256:' . $binaryDigest,
                'identity' => $identity,
            ];
        }
        ksort($installed, SORT_STRING);
        return $installed;
    }

    /**
     * @return array<string,array{
     *   version:string,
     *   probe:list<string>,
     *   platforms:array<string,array{url:string,sha256:string,archive:string,member:?string}>
     * }>
     */
    private function lock(): array
    {
        $bytes = file_get_contents($this->root . '/' . self::LOCK);
        if (!is_string($bytes)) {
            throw new CatalogException('development toolchain lock is absent');
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('development toolchain lock is invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($document)
            || array_keys($document) !== ['format', 'tools']
            || ($document['format'] ?? null) !== 'duo-development-toolchain-lock/v1'
            || !is_array($document['tools'] ?? null)
            || array_keys($document['tools']) !== ['actionlint', 'shellcheck', 'shfmt']) {
            throw new CatalogException('development toolchain lock has an invalid top-level contract');
        }
        $tools = [];
        foreach ($document['tools'] as $name => $tool) {
            if (!is_string($name) || !is_array($tool) || array_is_list($tool)
                || array_keys($tool) !== ['version', 'probe', 'platforms']
                || !is_string($tool['version'] ?? null)
                || !is_array($tool['probe'] ?? null)
                || !array_is_list($tool['probe'])
                || !is_array($tool['platforms'] ?? null)) {
                throw new CatalogException("development toolchain lock entry $name is malformed");
            }
            $probe = [];
            foreach ($tool['probe'] as $argument) {
                if (!is_string($argument) || $argument === '') {
                    throw new CatalogException("development tool $name has an invalid probe");
                }
                $probe[] = $argument;
            }
            $platforms = [];
            foreach ($tool['platforms'] as $platform => $source) {
                $member = is_array($source) ? ($source['member'] ?? null) : null;
                if (!is_string($platform) || !is_array($source) || array_is_list($source)
                    || array_keys($source) !== ['url', 'sha256', 'archive', 'member']
                    || !is_string($source['url'] ?? null)
                    || preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/~D', $source['url']) !== 1
                    || !is_string($source['sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $source['sha256']) !== 1
                    || !is_string($source['archive'] ?? null)
                    || !in_array($source['archive'], ['raw', 'tar.gz'], true)
                    || (!is_null($member) && !is_string($member))) {
                    throw new CatalogException("development tool $name source $platform is malformed");
                }
                if (($source['archive'] === 'raw') !== ($member === null)) {
                    throw new CatalogException("development tool $name source $platform has inconsistent archive metadata");
                }
                $platforms[$platform] = [
                    'url' => $source['url'],
                    'sha256' => $source['sha256'],
                    'archive' => $source['archive'],
                    'member' => $member,
                ];
            }
            $tools[$name] = [
                'version' => $tool['version'],
                'probe' => $probe,
                'platforms' => $platforms,
            ];
        }
        return $tools;
    }

    private function download(string $url, string $destination): void
    {
        $this->process([
            'curl', '--fail', '--location', '--silent', '--show-error',
            '--proto', '=https', '--tlsv1.2', '--output', $destination, $url,
        ], false);
    }

    private function extract(string $archive, ?string $member): string
    {
        if ($member === null || str_starts_with($member, '/') || in_array('..', explode('/', $member), true)) {
            throw new CatalogException('development tool archive member is unsafe');
        }
        return $this->process(['tar', '-xOzf', $archive, $member], true);
    }

    private function publishExecutable(string $destination, string $bytes): void
    {
        $temporary = tempnam(dirname($destination), '.executable.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create development tool executable');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes)
                || !chmod($temporary, 0755)
                || !rename($temporary, $destination)) {
                throw new CatalogException('cannot publish development tool executable');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param list<string> $argv */
    private function command(array $argv): string
    {
        $output = $this->process($argv, true);
        $identity = trim($output);
        if ($identity === '') {
            throw new CatalogException('development tool identity probe returned no output');
        }
        return $identity;
    }

    /** @param list<string> $argv */
    private function process(array $argv, bool $capture): string
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start development tool process');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout) || !is_string($stderr)) {
            $detail = trim(is_string($stderr) ? $stderr : '');
            throw new CatalogException('development tool process failed' . ($detail === '' ? '' : ': ' . $detail));
        }
        return $capture ? $stdout . ($stderr === '' ? '' : "\n" . $stderr) : '';
    }
}
